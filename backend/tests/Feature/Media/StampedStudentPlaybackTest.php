<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Media\Models\MediaCaption;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/*
| ⛔ THE STUDENT A TEACHER ONCE ADDED TO THEIR OWN WORKSPACE — watching a video they
| paid for at ANOTHER teacher.
|
| `users.last_workspace_id` is stamped by `addWorkspaceMember`, `AcceptInvitation`
| and the seeders, and `WorkspaceContext::id()` falls back to it, so the scope ANDs
| the OTHER workspace onto every query about this lesson. PR #142 found five
| layers of that on the LiveSessions doors; this file walks the Media ones.
|
| ⚠️ STAMPED WITH `forceFill`: `last_workspace_id` is in `User::$guarded`, so
| `create([...])` drops it in silence and rebuilds the null-context student.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    [$elsewhere] = $this->createWorkspaceWithOwner();

    $this->lesson = $this->lessonWithVideo($this->workspace);

    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $student->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();

    [$this->student] = $this->enrolledViewer($this->workspace, $this->lesson, $student);
});

it('opens the lesson page with its video', function (): void {
    $this->getJson("/api/v1/learn/lessons/{$this->lesson->uuid}")
        ->assertOk()
        ->assertJsonPath('lesson.has_asset', true);
});

it('plays the video, by byte range, and renews the grant', function (): void {
    $response = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")->assertOk();

    $this->get($response->json('manifest_url'))->assertOk();

    $this->postJson("/api/v1/playback/{$response->json('grant')}/renew", ['position_seconds' => 30])
        ->assertOk();
});

it('lists and serves the captions beside the video', function (): void {
    $asset = $this->lesson->mediaAsset;
    $path = 'captions/stamped.vtt';

    Storage::disk((string) config('media.disk'))->put($path, "WEBVTT\n\n1\n00:00:00.000 --> 00:00:02.000\nمرحباً\n");

    MediaCaption::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'media_asset_id' => $asset->getKey(),
        'storage_path' => $path,
    ]);

    $url = $this->postJson("/api/v1/lessons/{$this->lesson->uuid}/playback")
        ->assertOk()
        ->json('captions.0.url');

    // A scoped `captions` relation is an EMPTY list — no error, just no subtitles.
    expect($url)->not->toBeNull();

    $this->get($url)->assertOk();
});
