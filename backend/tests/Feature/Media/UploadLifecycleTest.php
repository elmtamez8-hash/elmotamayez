<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function lessonForUpload(): Lesson
{
    /** @var Workspace $workspace */
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): Lesson {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

        return Lesson::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'type' => 'video',
        ]);
    });
}

it('hands back a ticket the client can upload to', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'درس-١.mp4',
        'size_bytes' => 1_048_576,
        'duration_seconds' => 600,
    ])
        ->assertCreated()
        ->assertJsonPath('asset.status', 'pending')
        ->assertJsonStructure(['upload' => ['url', 'method', 'headers', 'expires_at']]);
});

// FR-003. Rejecting up front saves the teacher watching a gigabyte transfer that
// was always going to fail — the real check still happens against the file.
it('refuses an oversized upload before a byte moves', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    PlatformSettings::set('media.max_size_bytes', 1_000);

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'ضخم.mp4',
        'size_bytes' => 5_000_000,
    ])->assertStatus(422);
});

it('refuses a video longer than the limit', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    PlatformSettings::set('media.max_duration_seconds', 60);

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'طويل.mp4',
        'duration_seconds' => 7_200,
    ])->assertStatus(422);
});

it('rejects a file that is not a video whatever it is called', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    $upload = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'خدعة.mp4',
    ])->assertCreated();

    $assetUuid = $upload->json('asset.uuid');

    // An .mp4 extension over zip bytes is trivially easy, which is why the type
    // is settled from the content and never from the name.
    $this->call('PUT', "/api/v1/media/upload/{$assetUuid}", [], [], [], [], 'PK'.chr(3).chr(4).'not a video');

    $asset = MediaAsset::query()->withoutWorkspaceScope()->where('uuid', $assetUuid)->sole();
    app(CompleteMediaUpload::class)->handle($asset);

    expect($asset->fresh()->status)->toBe(MediaAssetStatus::Failed)
        ->and($asset->fresh()->failure_reason)->not->toBeNull();
});

// SC-010. A lesson must never claim it has a video when the upload broke.
it('leaves no lesson in an unplayable state after a broken upload', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    $assetUuid = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'مقطوع.mp4',
    ])->json('asset.uuid');

    // The client vanished mid-transfer: the row exists, the bytes do not.
    $asset = MediaAsset::query()->withoutWorkspaceScope()->where('uuid', $assetUuid)->sole();
    app(CompleteMediaUpload::class)->handle($asset);

    expect($asset->fresh()->status)->toBe(MediaAssetStatus::Failed)
        ->and($lesson->fresh()->mediaAsset?->isPlayable())->toBeFalse();
});

it('replaces the previous asset rather than orphaning it', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    $first = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", ['original_filename' => 'أول.mp4'])
        ->json('asset.uuid');
    $second = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", ['original_filename' => 'ثانٍ.mp4'])
        ->json('asset.uuid');

    expect($first)->not->toBe($second)
        ->and(MediaAsset::query()->withoutWorkspaceScope()->where('owner_id', $lesson->getKey())->count())->toBe(1);
});

it('refuses an upload from another workspace', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    [$other, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'Other']);
    Sanctum::actingAs($otherOwner);
    $this->setCurrentWorkspace($other, $otherOwner);

    // 404 rather than 403, and that is the stronger answer: the global scope hides
    // the lesson entirely, so the response does not confirm it exists.
    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", ['original_filename' => 'سرقة.mp4'])
        ->assertStatus(404);
});

it('refuses an upload from a member without the lesson permission', function (): void {
    Storage::fake('local');
    $lesson = lessonForUpload();

    /** @var Workspace $workspace */
    $workspace = $lesson->workspace;
    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    Sanctum::actingAs($student);
    $this->setCurrentWorkspace($workspace, $student);

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", ['original_filename' => 'ممنوع.mp4'])
        ->assertStatus(403);
});
