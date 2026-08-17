<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\RequestUploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\BunnyMediaProvider;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BunnyFixtures;

/*
| A VIDEO HOST RECEIVES VIDEO. EVERYTHING ELSE STAYS ON OUR OWN DISK.
|
| ⚠️ AND THIS WAS A LIVE DEFECT, NOT A HYPOTHETICAL — it shipped and was observed on a
| real account on 2026-08-17, the day `MEDIA_PROVIDER=bunny` was first set. Every
| upload went through whichever provider the config named, with no branch on kind, so a
| teacher's PDF worksheet had a *video object* created for it in a video-only library,
| under video-sized ceilings. It failed, and the message named the wrong cause.
|
| ⚠️ THE FIXTURE MUST CONFIGURE THE VIDEO HOST, OR THIS FILE MEASURES NOTHING. With the
| local provider configured every kind lands locally for the trivial reason, and the
| assertions below would hold over the unfixed code. The disagreement between "what the
| config names" and "what the kind needs" IS the test — the same shape SC-011 needs two
| assets for.
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(BunnyFixtures::config());
    config(['media.provider' => 'bunny']);

    // Nothing here should reach the network. A ticket for a document must not, and a
    // ticket for a video only creates a video — anything else is a stray call.
    Http::fake(fn () => Http::response(['guid' => 'new-guid'], 200));

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
});

it('sends video to the configured host and a document to our own disk', function (): void {
    $resolver = app(MediaProviderResolver::class);

    expect($resolver->forKind(MediaKind::Video))->toBeInstanceOf(BunnyMediaProvider::class)
        ->and($resolver->forKind(MediaKind::Document))->toBeInstanceOf(LocalMediaProvider::class)
        ->and($resolver->forKind(MediaKind::Audio))->toBeInstanceOf(LocalMediaProvider::class);
});

/*
| The declaration is on the provider, and this is what reads it. A provider that
| declares nothing takes everything — which is our own disk, and must stay that way as
| kinds are added.
*/
it('lets a provider that declares no kinds take all of them', function (): void {
    $local = app(LocalMediaProvider::class)->capabilities();

    foreach (MediaKind::cases() as $kind) {
        expect($local->accepts($kind))->toBeTrue();
    }

    expect(app(BunnyMediaProvider::class)->capabilities()->accepts(MediaKind::Document))
        ->toBeFalse();
});

/*
| ⚠️ THE COLUMN RECORDS WHO ACTUALLY TOOK IT, WHICH IS THE HALF THAT MATTERS LATER.
|
| A column stamped with the CONFIGURED provider would be a lie the moment a kind was
| routed elsewhere — and `MediaProviderResolver::for()` reads that column to decide who
| serves, deletes and re-checks the file. Routing without stamping would move the bug
| from the upload to every operation after it.
*/
it('stamps the asset with the provider that took it, not the configured one', function (): void {
    $lesson = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'type' => 'pdf',
    ]);

    $result = app(RequestUploadTicket::class)->handle(
        lesson: $lesson,
        originalFilename: 'worksheet.pdf',
        declaredSizeBytes: 2048,
        kind: MediaKind::Document,
    );

    expect($result['asset']->provider)->toBe('local')
        ->and($result['asset']->status)->toBe(MediaAssetStatus::Pending);

    // And the ticket points at our own route, which is the observable consequence:
    // a document ticket that pointed at the video host would fail on upload.
    expect($result['ticket']->url)->toContain('/api/v1/media/upload/');

    // No call was made about it. A document reaching the video host at all is the
    // defect, and a created video object is billed monthly for nothing.
    Http::assertNothingSent();
});

/*
| ⚠️ AND THE LOCAL UPLOAD ROUTE NOW ASKS WHOSE ASSET IT IS.
|
| The ticket token is the asset's uuid and the route is unauthenticated by design. What
| was missing is the provider check: with a video host configured, one of ITS assets
| could be handed bytes here, writing a local disk path into `provider_asset_id`.
| `CompleteMediaUpload` then asked the video host about a file that was never created
| there — a failure naming the wrong cause, plus a stray file on our disk.
*/
it('refuses bytes for an asset that belongs to another provider', function (): void {
    $asset = MediaAsset::factory()->create([
        'provider' => 'bunny',
        'provider_asset_id' => null,
        'status' => MediaAssetStatus::Pending,
    ]);

    $this->call('PUT', "/api/v1/media/upload/{$asset->uuid}", content: 'some bytes')
        ->assertNotFound();

    expect($asset->refresh()->provider_asset_id)->toBeNull()
        ->and($asset->status)->toBe(MediaAssetStatus::Pending);
});

// The mirror image, so the guard above is not asserting "this route refuses
// everything". A local asset still uploads after the switch — which is the case the
// resolver exists for, and the reason the check reads the COLUMN and not the config.
it('still accepts bytes for a local asset while a video host is configured', function (): void {
    $asset = MediaAsset::factory()->create([
        'provider' => 'local',
        'provider_asset_id' => null,
        'status' => MediaAssetStatus::Pending,
    ]);

    $this->call('PUT', "/api/v1/media/upload/{$asset->uuid}", content: 'some bytes')
        ->assertOk();

    expect($asset->refresh()->provider_asset_id)->not->toBeNull()
        ->and($asset->status)->toBe(MediaAssetStatus::Processing);
});
