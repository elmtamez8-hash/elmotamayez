<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/**
 * View-only versus allow-download, decided on the server.
 *
 * Hiding a download button in the browser is not preventing a download: a viewer
 * who has the URL asks for the bytes directly, and the only place that can
 * refuse is the place that serves them. So every assertion here is on the
 * `Content-Disposition` header, never on what a component renders (FR-036).
 *
 * And a document is reached the same way a video is — through a grant that
 * expires. That is what "view only" actually buys. Not copy protection, which
 * the web does not offer, but a link that stops working, which it does.
 */
function documentLessonFor(Workspace $workspace, bool $downloadable): Lesson
{
    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $downloadable): Lesson {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

        $lesson = Lesson::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'type' => 'pdf',
            'is_free' => false,
            'is_preview' => false,
        ]);

        $path = 'media/'.Str::uuid()->toString().'.pdf';

        MediaAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'owner_type' => Lesson::class,
            'owner_id' => $lesson->getKey(),
            'provider' => 'local',
            'provider_asset_id' => $path,
            'kind' => MediaKind::Document,
            'role' => MediaRole::Primary,
            'is_downloadable' => $downloadable,
            'status' => MediaAssetStatus::Ready,
            'original_filename' => 'malzama.pdf',
            'mime_type' => 'application/pdf',
            'ready_at' => now(),
        ]);

        Storage::disk('local')->put($path, '%PDF-1.4 body');

        return $lesson->refresh();
    });
}

function documentStreamUrl(bool $downloadable): string
{
    Storage::fake('local');

    [$workspace] = test()->createWorkspaceWithOwner();
    $lesson = documentLessonFor($workspace, $downloadable);
    test()->enrolledViewer($workspace, $lesson);

    return (string) test()->postJson("/api/v1/lessons/{$lesson->uuid}/playback")
        ->assertOk()
        ->json('manifest_url');
}

it('serves a view-only document inline', function (): void {
    $url = documentStreamUrl(downloadable: false);

    $response = $this->get($url)->assertOk();

    expect((string) $response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('serves a downloadable document as an attachment, under its own name', function (): void {
    $url = documentStreamUrl(downloadable: true);

    $response = $this->get($url)->assertOk();

    $disposition = (string) $response->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment')
        // Named as the teacher named it, not as a uuid. A downloaded worksheet
        // called `a273bb….pdf` is a file nobody finds again.
        ->and($disposition)->toContain('malzama.pdf');
});

it('reaches a document only through a grant', function (): void {
    documentStreamUrl(downloadable: true);

    // There is no permanent public path to a document, downloadable or not. One
    // refusal for every failure mode, revealing nothing about the asset, its
    // filename or where it is stored.
    $this->get('/api/v1/playback/'.Str::uuid()->toString().'/stream')->assertForbidden();
});

it('stops serving a document once the grant expires', function (): void {
    $url = documentStreamUrl(downloadable: true);

    $this->get($url)->assertOk();

    $this->travel(10)->minutes();

    // Re-decided on every range request, so expiry cuts a file already in
    // flight rather than only refusing the next viewer.
    $this->get($url)->assertForbidden();
});

it('lets the teacher flip the switch on a file already uploaded', function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $lesson = documentLessonFor($workspace, downloadable: false);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $asset = $lesson->mediaAsset;

    // Not part of the upload payload: the teacher changes their mind about a
    // file that is already there, and making them re-upload to change one
    // boolean is how a switch stops being used.
    $this->putJson("/api/v1/media/assets/{$asset?->uuid}/disposition", ['is_downloadable' => true])
        ->assertOk()
        ->assertJsonPath('is_downloadable', true);

    expect($asset?->refresh()->is_downloadable)->toBeTrue();
});
