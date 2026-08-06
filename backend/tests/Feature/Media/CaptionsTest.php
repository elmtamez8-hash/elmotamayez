<?php

declare(strict_types=1);

use App\Modules\Media\Models\MediaCaption;
use App\Modules\Media\Support\WebVtt;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

const VALID_VTT = <<<'VTT'
WEBVTT

1
00:00:01.000 --> 00:00:04.000
مرحباً بكم في الدرس الأول.

2
00:00:04.500 --> 00:00:09.000
سنتحدّث اليوم عن المعادلات.
VTT;

function vttFile(string $content = VALID_VTT): UploadedFile
{
    return UploadedFile::fake()->createWithContent('lesson.vtt', $content);
}

// FR-032.
it('accepts a WebVTT track and serves it to the player', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $asset = $lesson->mediaAsset;

    $this->post("/api/v1/media/assets/{$asset->uuid}/captions", ['file' => vttFile()])
        ->assertCreated()
        ->assertJsonPath('language', 'ar')
        ->assertJsonPath('is_default', true);

    // The viewer gets a URL, not a storage path — and it runs through the grant.
    [, $session] = $this->enrolledViewer($workspace, $lesson);
    expect($session)->not->toBeNull();

    $grant = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertOk();
    $url = $grant->json('captions.0.url');

    expect($url)->toContain($grant->json('grant'))
        ->and($url)->not->toContain('captions/'.$asset->uuid);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/vtt; charset=UTF-8');
});

it('refuses a file that would produce an empty track', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $asset = $lesson->mediaAsset;

    // No signature: the browser shows nothing and reports nothing.
    $this->post("/api/v1/media/assets/{$asset->uuid}/captions", ['file' => vttFile('مرحباً')])
        ->assertStatus(422);

    // Signature, but no cue with a timing line.
    $this->post("/api/v1/media/assets/{$asset->uuid}/captions", ['file' => vttFile("WEBVTT\n\nمرحباً")])
        ->assertStatus(422);

    expect(MediaCaption::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('replaces a track of the same language rather than duplicating it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $asset = $lesson->mediaAsset;

    $this->post("/api/v1/media/assets/{$asset->uuid}/captions", ['file' => vttFile()])->assertCreated();
    $this->post("/api/v1/media/assets/{$asset->uuid}/captions", ['file' => vttFile()])->assertCreated();

    expect(MediaCaption::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('removes the row and the file', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $uuid = $this->post("/api/v1/media/assets/{$lesson->mediaAsset->uuid}/captions", ['file' => vttFile()])
        ->json('uuid');

    $this->deleteJson("/api/v1/media/captions/{$uuid}")->assertNoContent();

    expect(MediaCaption::query()->withoutWorkspaceScope()->count())->toBe(0);
});

// FR-034. Derived from the cues, never stored — a transcript column would be a
// second copy of the same words, and the two drift apart at the first typo fix.
it('derives the full transcript from the cues', function (): void {
    expect(WebVtt::transcript(VALID_VTT))
        ->toBe('مرحباً بكم في الدرس الأول. سنتحدّث اليوم عن المعادلات.');

    // Speaker tags belong to the renderer, not to the words someone reads.
    expect(WebVtt::transcript("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<v نورة>مرحباً"))
        ->toBe('مرحباً');
});

it('refuses a caption file to anyone without a live grant', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $lesson = $this->lessonWithVideo($workspace);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $uuid = $this->post("/api/v1/media/assets/{$lesson->mediaAsset->uuid}/captions", ['file' => vttFile()])
        ->json('uuid');

    [, $session] = $this->enrolledViewer($workspace, $lesson);
    $url = $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->json('captions.0.url');

    $this->get($url)->assertOk();

    // The script stops being readable at the same moment the video does.
    $this->travel(10)->minutes();

    $this->get($url)->assertForbidden();

    expect($session->status)->not->toBeNull()
        ->and($uuid)->not->toBeNull();
});
