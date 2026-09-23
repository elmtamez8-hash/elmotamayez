<?php

declare(strict_types=1);

use App\Modules\Community\Models\Conversation;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Support\Facades\Storage;

/*
| ⛔ `PUT /media/upload/{token}` WAS AN UNAUTHENTICATED WRITE WITH NO CEILING.
|
| The token was the asset's uuid — which API payloads carry, so it is an
| identifier and not a secret — and the route wrote whatever arrived, of any
| size nginx let through, for as long as the asset stayed pending. The ticket is
| a temporary signed url now, and the route counts the bytes against the ceiling
| for that asset before they land.
|
| Both directions: the ticket's own url is accepted (the frontend only ever uses
| the url it is given, so this IS the proof that uploading still works), and
| every other way in is refused with nothing written.
*/

function signedUploadAsset(array $attributes = []): MediaAsset
{
    return MediaAsset::factory()->create(array_merge([
        'provider' => 'local',
        'provider_asset_id' => null,
        'status' => MediaAssetStatus::Pending,
        'kind' => MediaKind::Video,
    ], $attributes));
}

function signedUploadUrl(MediaAsset $asset): string
{
    return app(LocalMediaProvider::class)->createUploadTicket($asset)->url;
}

beforeEach(function (): void {
    Storage::fake('local');

    // The asset row needs a workspace; the upload itself carries no user at all.
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
});

it('accepts the bytes at the url the ticket names', function (): void {
    $asset = signedUploadAsset();

    $this->call('PUT', signedUploadUrl($asset), content: 'some bytes')->assertOk();

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Processing)
        ->and($asset->provider_asset_id)->not->toBeNull();
});

it('refuses the bare uuid url that used to be the whole credential', function (): void {
    $asset = signedUploadAsset();

    $this->call('PUT', "/api/v1/media/upload/{$asset->uuid}", content: 'some bytes')->assertForbidden();

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Pending)
        ->and($asset->provider_asset_id)->toBeNull();
});

it('refuses one asset\'s signature presented for another asset', function (): void {
    $mine = signedUploadAsset();
    $theirs = signedUploadAsset();

    $tampered = str_replace($mine->uuid, $theirs->uuid, signedUploadUrl($mine));

    $this->call('PUT', $tampered, content: 'some bytes')->assertForbidden();

    expect($theirs->refresh()->status)->toBe(MediaAssetStatus::Pending);
});

it('refuses a ticket after it expires', function (): void {
    $asset = signedUploadAsset();
    $url = signedUploadUrl($asset);

    $this->travel((int) config('media.upload_ticket_ttl_seconds') + 60)->seconds();

    $this->call('PUT', $url, content: 'some bytes')->assertForbidden();

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Pending);
});

it('accepts the signed url on the other host nginx answers', function (): void {
    // `sameOriginIfOurs()` re-points the url at the page's own origin, and the
    // site is served on both the bare and the www host. The signature is over the
    // path and query, so the host does not matter.
    $asset = signedUploadAsset();
    $url = (string) preg_replace('#^https?://[^/]+#', 'https://www.example.test', signedUploadUrl($asset));

    $this->call('PUT', $url, content: 'some bytes')->assertOk();
});

it('refuses an honestly declared body above the kind\'s ceiling before reading it', function (): void {
    PlatformSettings::set('media.max_size_bytes', 8);
    $asset = signedUploadAsset();

    $this->call('PUT', signedUploadUrl($asset), server: ['CONTENT_LENGTH' => '9'], content: '123456789')
        ->assertStatus(413);

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Pending)
        ->and($asset->provider_asset_id)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('counts the bytes when the declared length lies', function (): void {
    PlatformSettings::set('media.max_size_bytes', 8);
    $asset = signedUploadAsset();

    $this->call('PUT', signedUploadUrl($asset), server: ['CONTENT_LENGTH' => '4'], content: str_repeat('x', 64))
        ->assertStatus(413);

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Pending)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('accepts a body exactly at the ceiling', function (): void {
    PlatformSettings::set('media.max_size_bytes', 8);
    $asset = signedUploadAsset();

    $this->call('PUT', signedUploadUrl($asset), content: '12345678')->assertOk();
});

it('holds a chat attachment to the chat allowance, not its kind\'s', function (): void {
    // A picture travels as a Document (50 MiB) and a voice note as Audio
    // (200 MiB); the chat allowance is the smaller number on purpose.
    PlatformSettings::set('media.max_chat_attachment_bytes', 4);
    $asset = signedUploadAsset([
        'kind' => MediaKind::Document,
        'owner_type' => Conversation::class,
    ]);

    $this->call('PUT', signedUploadUrl($asset), content: '12345')->assertStatus(413);

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Pending);
});
