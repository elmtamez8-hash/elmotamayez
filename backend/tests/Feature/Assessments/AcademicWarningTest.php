<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\FinalizeAttempt;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;

/*
| `academic_warning` — N graded failures in a row in ONE course (owner decision
| 2026-09-24; N is `assessments.academic_warning_consecutive_fails`, default 2).
|
| Every paper here goes through the real `FinalizeAttempt`, which is what fires
| `ExamFailed` — a fixture that dispatched the event by hand would skip the one
| judgement (non-practice, stored `passed = false`) the rule rests on. A paper
| with no items scores 0, so the exam's `passing_score` alone decides: 60 fails
| it, 0 passes it.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'title' => 'الفيزياء']);
});

function academicWarningExam(Course $course, bool $failing): Exam
{
    return Exam::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'passing_score' => $failing ? 60 : 0,
    ]);
}

/** One sitting, finalized for real, a minute after the previous one. */
function academicWarningSit(Course $course, User $student, bool $fails, bool $practice = false): Attempt
{
    test()->travel(1)->minutes();

    $attempt = Attempt::query()->create([
        'workspace_id' => $course->workspace_id,
        'exam_id' => academicWarningExam($course, $fails)->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => Attempt::STATUS_GRADING,
        'is_practice' => $practice,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    return app(FinalizeAttempt::class)->handle($attempt);
}

function academicWarnings(User $recipient): int
{
    return Notification::query()
        ->where('recipient_user_id', $recipient->getKey())
        ->where('type', NotificationType::AcademicWarning->value)
        ->count();
}

it('warns the student and the guardian with the academic-warnings consent after two failures in a row', function (): void {
    $warned = guardianOf($this->student, [GuardianPermission::AcademicWarnings]);
    $resultsOnly = guardianOf($this->student, [GuardianPermission::Results]);

    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(0);

    $second = academicWarningSit($this->course, $this->student, fails: true);

    $row = assertNotifiedOnce($this->student, NotificationType::AcademicWarning);
    assertNotifiedOnce($warned, NotificationType::AcademicWarning);

    expect((string) $row->body)->toContain('الفيزياء')
        ->and(academicWarnings($resultsOnly))->toBe(0)
        ->and(Attempt::query()->withoutWorkspaceScope()->find($second->getKey())->academic_warning_at)->not->toBeNull();
});

it('says nothing when a pass breaks the run', function (): void {
    academicWarningSit($this->course, $this->student, fails: true);
    academicWarningSit($this->course, $this->student, fails: false);
    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(0);
});

it('warns once per run, and again only after a pass and a fresh run', function (): void {
    academicWarningSit($this->course, $this->student, fails: true);
    academicWarningSit($this->course, $this->student, fails: true);
    academicWarningSit($this->course, $this->student, fails: true);
    // The fourth matters separately: its last-two window holds no stamped
    // paper, so only «the run is already longer than two» stops it.
    academicWarningSit($this->course, $this->student, fails: true);

    // The third and fourth failures are inside the run already warned about.
    expect(academicWarnings($this->student))->toBe(1);

    academicWarningSit($this->course, $this->student, fails: false);
    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(1);

    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(2);
});

it('does not warn twice when a failed paper is finalized again', function (): void {
    $first = academicWarningSit($this->course, $this->student, fails: true);
    $second = academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(1);

    // `ReviseGrade` re-finalizes a paper after a corrected mark: `ExamFailed`
    // fires again, and the paper's `finalized_at` moves to now — for the paper
    // that completed the run, and for the earlier one that it moves back on top.
    $this->travel(1)->minutes();
    app(FinalizeAttempt::class)->handle($second->refresh());
    $this->travel(1)->minutes();
    app(FinalizeAttempt::class)->handle($first->refresh());

    expect(academicWarnings($this->student))->toBe(1);
});

it('is switched off by a threshold of zero', function (): void {
    PlatformSettings::set('assessments.academic_warning_consecutive_fails', 0);

    academicWarningSit($this->course, $this->student, fails: true);
    academicWarningSit($this->course, $this->student, fails: true);
    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(0);
});

it('reads the threshold from the platform setting', function (): void {
    PlatformSettings::set('assessments.academic_warning_consecutive_fails', 3);

    academicWarningSit($this->course, $this->student, fails: true);
    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(0);

    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(1);
});

it('neither counts nor breaks a run with a practice paper', function (): void {
    academicWarningSit($this->course, $this->student, fails: true, practice: true);
    academicWarningSit($this->course, $this->student, fails: true);

    // A practice paper is stored `passed = false` whatever it scored — it is
    // still not a failure, so one real failure is not yet a run.
    expect(academicWarnings($this->student))->toBe(0);

    academicWarningSit($this->course, $this->student, fails: false, practice: true);
    academicWarningSit($this->course, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(1);
});

it('does not count a paper still waiting on a grader', function (): void {
    academicWarningSit($this->course, $this->student, fails: true);

    $pending = Attempt::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'exam_id' => academicWarningExam($this->course, true)->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => Attempt::STATUS_PENDING_GRADING,
        'is_practice' => false,
        'score' => 0,
        'max_score' => 100,
        'passed' => false,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    expect(academicWarnings($this->student))->toBe(0);

    // Marked, and failed: NOW it is the second failure of the run.
    $this->travel(1)->minutes();
    app(FinalizeAttempt::class)->handle($pending);

    expect(academicWarnings($this->student))->toBe(1);
});

it('never combines failures from two courses', function (): void {
    $other = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    academicWarningSit($this->course, $this->student, fails: true);
    academicWarningSit($other, $this->student, fails: true);

    expect(academicWarnings($this->student))->toBe(0);
});
