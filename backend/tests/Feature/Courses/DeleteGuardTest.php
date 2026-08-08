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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Two deletions that destroy something nobody can get back.
 *
 * A `lesson_progress` row is the record that a student did the work, and their
 * percentage is computed from those rows. An uploaded asset is the teacher's own
 * work, and destroying one is deliberately gated behind two-factor
 * authentication since 004 — a lesson delete that took the video down by cascade
 * would be a back door onto that decision.
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

it('destroys a lesson nobody has touched', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, , , $lesson] = guardedLesson($workspace->id);

    Sanctum::actingAs($owner);

    $this->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}")->assertNoContent();

    expect(Lesson::where('id', $lesson->id)->exists())->toBeFalse();
});
