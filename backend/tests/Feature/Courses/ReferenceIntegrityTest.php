<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * A reference item is a POSITION, and the exam is the thing (`FR-044` · `FR-045`).
 *
 * Two directions, and both of them are ways to lose data by accident:
 *
 * Removing the item must leave the exam and every attempt on it untouched
 * (`SC-013`). A teacher rearranging their tree is not saying "delete my students'
 * marks", and a cascade here would say it for them.
 *
 * Deleting the exam must remove the item from what students read — because
 * `reference_id` has no foreign key by design (the tables live in two modules),
 * so nothing at the database level stops the row pointing at a gap. And the row
 * matters: an exam item is completable, so an unreachable one caps every enrolled
 * student below 100% permanently.
 */

/** @return array{0: Course, 1: Lesson, 2: Exam, 3: Workspace, 4: User} */
function referencedTree(): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner): array {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->id]);

        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'قسم', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'فصل', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $exam = Exam::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'course_id' => $course->id,
            'title' => 'اختبار الوحدة',
        ]);

        $item = Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => 'اختبار الوحدة', 'type' => 'exam',
            'status' => ContentStatus::Published, 'order' => 1,
            'reference_id' => $exam->id, 'exam_gate' => ExamGate::Attempt,
        ]);

        return [$course, $item, $exam, $workspace, $owner];
    });
}

it('deletes the position and leaves the exam and its attempts alone', function (): void {
    [$course, $item, $exam, $workspace] = referencedTree();
    $student = test()->addWorkspaceMember($workspace, 'student');

    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'student_user_id' => $student->id, 'source' => 'manual', 'status' => 'active',
        'progress_pct' => 0, 'enrolled_at' => now(),
    ]);

    $attempt = Attempt::create([
        'workspace_id' => $workspace->id, 'exam_id' => $exam->id,
        'enrollment_id' => $enrollment->id, 'student_user_id' => $student->id,
        'status' => 'graded', 'score' => 90, 'max_score' => 100, 'passed' => true,
        'random_seed' => 1, 'started_at' => now(), 'submitted_at' => now(),
    ]);

    test()->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$item->uuid}")->assertNoContent();

    expect(Lesson::query()->whereKey($item->id)->exists())->toBeFalse()
        // The exam is a thing; the item was a place to find it. Removing the
        // place must not remove the marks anybody earned.
        ->and(Exam::query()->whereKey($exam->id)->exists())->toBeTrue()
        ->and(Attempt::query()->whereKey($attempt->id)->exists())->toBeTrue();
});

it('hides an item whose exam was deleted from what a student reads', function (): void {
    [$course, $item, $exam, $workspace] = referencedTree();
    $student = test()->addWorkspaceMember($workspace, 'student');

    Exam::query()->whereKey($exam->id)->delete();

    Sanctum::actingAs($student);

    $sections = test()->getJson("/api/v1/courses/{$course->uuid}/sections")->assertOk()->json();

    $uuids = collect($sections['data'] ?? $sections)
        ->pluck('chapters')->flatten(1)
        ->pluck('lessons')->flatten(1)
        ->pluck('uuid');

    // No foreign key stands here — the tables are in two modules — so the guard
    // is the read itself. A row pointing at a gap is not content.
    expect($uuids)->not->toContain($item->uuid)
        // And still a row: nothing deleted it, so the teacher can still find and
        // fix it. It is invisible, not gone.
        ->and(Lesson::query()->withoutWorkspaceScope()->whereKey($item->id)->exists())->toBeTrue();
});

it('keeps an orphaned item out of the progress denominator', function (): void {
    [$course, , $exam] = referencedTree();

    expect($course->lessons()->countableForProgress()->count())->toBe(1);

    Exam::query()->whereKey($exam->id)->delete();

    // Left in, this is the recording bug again: an item nobody can complete caps
    // every enrolled student below 100%, so `CourseCompleted` never fires and no
    // certificate ever issues (FR-045 · FR-026أ).
    expect($course->lessons()->countableForProgress()->count())->toBe(0);
});

it('shows the teacher that the item is broken rather than hiding it', function (): void {
    [$course, $item, $exam] = referencedTree();

    Exam::query()->whereKey($exam->id)->delete();

    $tree = test()->getJson("/api/v1/courses/{$course->uuid}/tree")->assertOk()->json();

    $row = collect($tree['sections'])->pluck('chapters')->flatten(1)
        ->pluck('lessons')->flatten(1)
        ->firstWhere('uuid', $item->uuid);

    // The author is the only person who can repoint or remove it. A row that
    // silently vanished from their outline while a student's percentage moved
    // would be the worst of both.
    expect($row)->not->toBeNull()
        ->and($row['reference_missing'])->toBeTrue();
});
