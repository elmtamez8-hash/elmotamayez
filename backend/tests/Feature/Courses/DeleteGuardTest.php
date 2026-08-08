<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Two deletions that destroy something nobody can get back.
 *
 * A `lesson_progress` row is the record that a student did the work, and their
 * percentage is computed from those rows. The item's own file is the teacher's own
 * work, and destroying one is deliberately gated behind two-factor authentication
 * since 004 — a lesson delete that took the video down by cascade would be a back
 * door onto that decision.
 *
 * **Attachments are the deliberate exception** (FR-038ب), and it is pinned here
 * rather than left as prose: they follow the item with no second factor, through
 * `DeleteMediaAsset` so the bytes go with the row.
 */
function guardedLesson(int $workspaceId): array
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'S', 'status' => ContentStatus::Published,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'C', 'status' => ContentStatus::Published,
    ]);

    $lesson = Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'L', 'type' => 'article',
        'status' => ContentStatus::Published, 'content' => 'x',
    ]);

    return [$course, $section, $chapter, $lesson];
}

it('refuses to destroy a lesson a student has progress on and offers archiving', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, , , $lesson] = guardedLesson($workspace->id);

    $student = $this->addWorkspaceMember($workspace, 'student');
    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    LessonProgress::create([
        'workspace_id' => $workspace->id, 'enrollment_id' => $enrollment->id,
        'lesson_id' => $lesson->id, 'status' => 'completed', 'completed_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}")
        // 423, not 422: nothing about the request was wrong.
        ->assertStatus(423)
        ->assertJsonPath('alternative', 'archive');

    expect(Lesson::where('id', $lesson->id)->exists())->toBeTrue()
        ->and(LessonProgress::where('lesson_id', $lesson->id)->exists())->toBeTrue();
});

it('refuses to destroy a section containing such a lesson', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $section, , $lesson] = guardedLesson($workspace->id);

    $student = $this->addWorkspaceMember($workspace, 'student');
    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    LessonProgress::create([
        'workspace_id' => $workspace->id, 'enrollment_id' => $enrollment->id,
        'lesson_id' => $lesson->id, 'status' => 'completed', 'completed_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    // The same act with more rows — the guard cannot only live on the leaf.
    $this->deleteJson("/api/v1/courses/{$course->uuid}/sections/{$section->uuid}")
        ->assertStatus(423);

    expect(Section::where('id', $section->id)->exists())->toBeTrue();
});

it('refuses to destroy a lesson that owns an uploaded asset', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, , , $lesson] = guardedLesson($workspace->id);

    MediaAsset::create([
        'workspace_id' => $workspace->id,
        'owner_type' => Lesson::class,
        'owner_id' => $lesson->id,
        'provider' => 'local',
        'kind' => MediaKind::Video,
        'role' => MediaRole::Primary,
        'status' => MediaAssetStatus::Ready,
        'original_filename' => 'lecture.mp4',
    ]);

    Sanctum::actingAs($owner);

    // Deleting the asset is gated behind 2FA. Cascading it from an ungated
    // lesson delete would make that gate optional.
    $this->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}")
        ->assertStatus(423);

    expect(Lesson::where('id', $lesson->id)->exists())->toBeTrue();
});

it('destroys a lesson that carries only attachments, and takes their bytes', function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, , , $lesson] = guardedLesson($workspace->id);

    Storage::disk('local')->put('media/worksheet.pdf', '%PDF-1.4');

    $attachment = MediaAsset::create([
        'workspace_id' => $workspace->id,
        'owner_type' => Lesson::class,
        'owner_id' => $lesson->id,
        'provider' => 'local',
        'kind' => MediaKind::Document,
        'role' => MediaRole::Attachment,
        'status' => MediaAssetStatus::Ready,
        'original_filename' => 'ورقة-عمل.pdf',
        // Where the LOCAL PROVIDER looks — it deletes `provider_asset_id`, and a
        // fixture that set some other column would have asserted nothing.
        'provider_asset_id' => 'media/worksheet.pdf',
    ]);

    Sanctum::actingAs($owner);

    // The line the guard draws, pinned in both directions (FR-038ب). The test
    // above proves a PRIMARY asset refuses the delete; this one proves an
    // attachment does not. Nothing else in the suite says so, and the contract
    // used to claim the two-factor route was the only door to destroying "an
    // uploaded asset" — true of the item's own file, and not of a worksheet
    // hanging off it.
    //
    // A primary asset IS the item; an attachment sits beside it, and an item with
    // three worksheets would otherwise need three two-factor confirmations before
    // it could be deleted at all.
    $this->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}")->assertNoContent();

    expect(Lesson::where('id', $lesson->id)->exists())->toBeFalse()
        ->and(MediaAsset::query()->whereKey($attachment->id)->exists())->toBeFalse()
        // Through DeleteMediaAsset, not a bulk delete on the relation: that form
        // leaves the bytes on disk with no row pointing at them, and revokes no
        // live grant (FR-038).
        ->and(Storage::disk('local')->exists('media/worksheet.pdf'))->toBeFalse();
});

it('sweeps attachment bytes when a whole chapter or section goes', function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $section, , $lesson] = guardedLesson($workspace->id);

    Storage::disk('local')->put('media/deep.pdf', '%PDF-1.4');

    $attachment = MediaAsset::create([
        'workspace_id' => $workspace->id,
        'owner_type' => Lesson::class,
        'owner_id' => $lesson->id,
        'provider' => 'local',
        'kind' => MediaKind::Document,
        'role' => MediaRole::Attachment,
        'status' => MediaAssetStatus::Ready,
        'original_filename' => 'ورقة.pdf',
        'provider_asset_id' => 'media/deep.pdf',
    ]);

    Sanctum::actingAs($owner);

    // Deleting the SECTION reaches the same state as deleting the item, and used
    // to reach it by a different road: `$chapter->lessons()->delete()`, the bulk
    // relation delete FR-038ب forbids. A query-builder delete retrieves no models
    // and fires no events, so the lesson rows went and the attachment's bytes
    // stayed on disk with nothing able to list or remove them — and any live
    // playback grant was never revoked, since PlaybackGuard checks the grant and
    // the asset, never the lesson.
    //
    // The guard permits this delete because it asks about `role = primary` alone,
    // which is exactly what makes the attachment path reachable here.
    $this->deleteJson("/api/v1/courses/{$course->uuid}/sections/{$section->uuid}")->assertNoContent();

    expect(Lesson::query()->whereKey($lesson->id)->exists())->toBeFalse()
        ->and(MediaAsset::query()->whereKey($attachment->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('media/deep.pdf'))->toBeFalse();
});

it('destroys a lesson nobody has touched', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, , , $lesson] = guardedLesson($workspace->id);

    Sanctum::actingAs($owner);

    $this->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}")->assertNoContent();

    expect(Lesson::where('id', $lesson->id)->exists())->toBeFalse();
});
