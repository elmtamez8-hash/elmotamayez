<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Events\CourseCompleted;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ A COMPLETED ENROLMENT KEEPS THE COURSE — AND THAT INCLUDES FINISHING WHAT THE
| TEACHER ADDS AFTERWARDS (owner decision 2026-09-23).
|
| Teachers build a course lesson by lesson. A student at 100% is at 100% of what
| existed that day; the next published lesson drops them below it (FR-050 keeps
| the status `completed`). `LessonGate` already opened that lesson to them — but
| the «أكملت» button asked `isActive()` and answered 403 «This enrollment is not
| active», and the exam and homework listeners asked the same, so the student
| could never get back to 100%.
|
| ⛔ AND THE SECOND 100% IS NOT A SECOND COMPLETION. `CourseProgress::sync()`
| answers «complete now», not «just became complete», and `MarkLessonComplete`
| used to fire `CourseCompleted` on that answer — invisible while a completed
| row could not reach it. The event fires on the transition only.
*/

beforeEach(function (): void {
    Event::fake([CourseCompleted::class]);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = $this->addWorkspaceMember($this->workspace, 'student');

    $this->course = Course::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
        'status' => 'published',
        'is_sequential' => false,
        'price_minor' => 0,
    ]);

    $this->section = Section::create([
        'workspace_id' => $this->workspace->id, 'course_id' => $this->course->id,
        'title' => 'Section 1', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $this->chapter = Chapter::create([
        'workspace_id' => $this->workspace->id, 'section_id' => $this->section->id,
        'course_id' => $this->course->id,
        'title' => 'Chapter 1', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $first = completedEnrollmentLesson($this, 1);

    $this->enrollment = Enrollment::create([
        'workspace_id' => $this->workspace->id,
        'uuid' => Str::uuid(),
        'course_id' => $this->course->id,
        'student_user_id' => $this->student->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    // Finish the course as it stands today — the real road to `completed`.
    app(MarkLessonComplete::class)->handle($this->enrollment, (int) $first->id);

    expect($this->enrollment->refresh()->status)->toBe('completed')
        ->and((float) $this->enrollment->progress_pct)->toBe(100.0);
    Event::assertDispatchedTimes(CourseCompleted::class, 1);
});

/** A published article at `$order` in the fixture's chapter. */
function completedEnrollmentLesson(object $test, int $order): Lesson
{
    return Lesson::create([
        'workspace_id' => $test->workspace->id,
        'course_id' => $test->course->id,
        'section_id' => $test->section->id,
        'chapter_id' => $test->chapter->id,
        'uuid' => Str::uuid(),
        'title' => "Lesson {$order}",
        'type' => 'article',
        'status' => ContentStatus::Published,
        'content' => "Content {$order}",
        'order' => $order,
    ]);
}

it('lets a completed student finish a lesson published after they reached 100%', function (): void {
    $added = completedEnrollmentLesson($this, 2);

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/enrollments/{$this->enrollment->uuid}/lessons/{$added->uuid}/complete")
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('progress_pct', 100);

    expect($this->enrollment->refresh()->status)->toBe('completed')
        ->and((float) $this->enrollment->progress_pct)->toBe(100.0);

    // The course was finished once. Reaching 100% again announces nothing.
    Event::assertDispatchedTimes(CourseCompleted::class, 1);
});

it('credits homework handed in after the course was completed', function (): void {
    $assignment = Assignment::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'course_id' => $this->course->id,
        'created_by' => $this->owner->id,
        'due_at' => now()->addDays(3),
    ]);

    $item = Lesson::create([
        'workspace_id' => $this->workspace->id, 'course_id' => $this->course->id,
        'section_id' => $this->section->id, 'chapter_id' => $this->chapter->id,
        'uuid' => Str::uuid(), 'title' => 'Homework', 'type' => 'assignment',
        'reference_id' => $assignment->id,
        'status' => ContentStatus::Published, 'order' => 2,
    ]);

    app(SubmitAssignment::class)->handle($assignment, $this->student, 'my answer');

    expect(LessonProgress::query()->withoutWorkspaceScope()
        ->where('enrollment_id', $this->enrollment->id)
        ->where('lesson_id', $item->id)
        ->value('status'))->toBe('completed')
        ->and((float) $this->enrollment->refresh()->progress_pct)->toBe(100.0);

    Event::assertDispatchedTimes(CourseCompleted::class, 1);
});

it('still refuses a cancelled enrolment at the complete button', function (): void {
    // The widening is `completed`, not «anything»: the status column is free
    // text, and every other value stays refused by construction.
    $this->enrollment->update(['status' => 'cancelled']);
    $added = completedEnrollmentLesson($this, 2);

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/enrollments/{$this->enrollment->uuid}/lessons/{$added->uuid}/complete")
        ->assertForbidden();
});
