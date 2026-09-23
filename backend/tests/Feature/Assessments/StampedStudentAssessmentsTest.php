<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE STUDENT A TEACHER ONCE ADDED TO THEIR OWN WORKSPACE — sitting an exam,
| handing in homework and reading a certificate at ANOTHER teacher.
|
| `users.last_workspace_id` is stamped by `addWorkspaceMember`, `AcceptInvitation`
| and the seeders, and `WorkspaceContext::id()` falls back to it, so the scope ANDs
| the OTHER workspace onto the implicit binding, the policy's workspace check and
| the list queries. Every null-context test in Assessments is green over it:
| measured 2026-09-23, each door below answered 404 or an empty list for this
| student while the null-context control answered 200.
|
| ⚠️ STAMPED WITH `forceFill`: `last_workspace_id` is in `User::$guarded`.
*/

beforeEach(function (): void {
    [$this->ws, $owner] = $this->createWorkspaceWithOwner();
    [$elsewhere] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->ws, $owner);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->ws->getKey(), 'created_by' => $owner->getKey(),
    ]);
    $this->student = User::factory()->create();
    $this->enrollment = $this->createEnrollment($this->ws, $this->course, $this->student);

    $this->exam = Exam::factory()->published()->create([
        'workspace_id' => $this->ws->getKey(), 'course_id' => $this->course->getKey(),
    ]);
    $this->homework = Assignment::create([
        'workspace_id' => $this->ws->getKey(), 'course_id' => $this->course->getKey(),
        'created_by' => $owner->getKey(), 'title' => 'STAMPED-HW', 'points' => 10,
        'submission_type' => 'text', 'status' => 'published', 'published_at' => now(),
    ]);
    $this->certificate = Certificate::create([
        'workspace_id' => $this->ws->getKey(), 'certificate_number' => 'C-1', 'verification_code' => 'VCODE1',
        'enrollment_id' => $this->enrollment->getKey(), 'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(), 'student_display_name' => 'S',
        'teacher_display_name' => 'T', 'issue_reason' => 'course_completed', 'issued_at' => now(),
    ]);

    $this->student->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();
    Sanctum::actingAs($this->student);
    $this->asGuest();
});

it('opens, starts and submits the exam, and reads the attempt', function (): void {
    $fx = adaptiveFixture(['easy']);
    $question = $fx['questions']->first();

    $exam = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $question): Exam {
        $exam = Exam::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(), 'status' => 'published', 'max_attempts' => 3,
        ]);
        ExamItem::create([
            'workspace_id' => $fx['workspace']->getKey(), 'exam_id' => $exam->getKey(),
            'question_id' => $question->getKey(), 'order' => 1,
        ]);

        return $exam;
    });

    [$elsewhere] = $this->createWorkspaceWithOwner();
    $fx['student']->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();
    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $this->getJson("/api/v1/exams/{$exam->uuid}")->assertOk();

    $start = $this->postJson("/api/v1/exams/{$exam->uuid}/attempts")->assertCreated();
    // A scoped snapshot is a paper with no questions — a denominator of zero.
    expect($start->json('questions'))->toHaveCount(1);
    $uuid = $start->json('attempt.uuid');

    $this->postJson("/api/v1/attempts/{$uuid}/submit", [
        'answers' => [[
            'question_id' => (int) $question->getKey(),
            'selected_option_ids' => [adaptiveRightOption((int) $question->getKey())],
        ]],
    ])->assertOk();
    $this->getJson("/api/v1/attempts/{$uuid}")->assertOk();

    // ⚠️ Written into the EXAM'S workspace, not the stamped one — or the teacher
    // never sees the paper while the student's screen says it was handed in.
    $attempt = Attempt::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    expect($attempt->workspace_id)->toBe($fx['workspace']->getKey())
        ->and($attempt->status)->toBe(Attempt::STATUS_GRADED)
        ->and((float) $attempt->score)->toBe(100.0);
});

it('lists the exam and the homework', function (): void {
    expect($this->getJson('/api/v1/exams')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/assignments')->assertOk()->json('data'))->toHaveCount(1);
});

it('opens and hands in the homework', function (): void {
    $this->getJson("/api/v1/assignments/{$this->homework->uuid}")->assertOk();
    $this->postJson("/api/v1/assignments/{$this->homework->uuid}/submissions", ['answer_text' => 'x'])
        ->assertCreated();

    expect(Submission::query()->withoutWorkspaceScope()->where('assignment_id', $this->homework->getKey())->value('workspace_id'))
        ->toBe($this->ws->getKey());

    // And the teacher's view of it still carries the student's own submission.
    expect($this->getJson("/api/v1/assignments/{$this->homework->uuid}")->json('data.my_submission'))->not->toBeNull();
});

it('still refuses an exam in a course the student is not enrolled in', function (): void {
    $other = Course::factory()->published()->create(['workspace_id' => $this->ws->getKey()]);
    $foreign = Exam::factory()->published()->create([
        'workspace_id' => $this->ws->getKey(), 'course_id' => $other->getKey(),
    ]);

    expect($this->getJson("/api/v1/exams/{$foreign->uuid}")->status())->toBeIn([403, 404]);
});

it('lists and opens their own certificate', function (): void {
    expect($this->getJson('/api/v1/certificates')->assertOk()->json('data'))->toHaveCount(1);
    $this->getJson("/api/v1/certificates/{$this->certificate->uuid}")->assertOk();
});

it('reaches the private-session request door', function (): void {
    // 422 is the form speaking; 404 was the binding refusing the course.
    $this->postJson("/api/v1/courses/{$this->course->uuid}/private-session-requests", [])
        ->assertStatus(422);
});

it('cancels their own booking', function (): void {
    Queue::fake([CloseClassSessionJob::class]);

    $teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->ws->getKey(), 'user_id' => User::factory()->create()->getKey(),
    ]);
    $session = ClassSession::factory()->create([
        'workspace_id' => $this->ws->getKey(), 'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(), 'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHour(), 'duration_minutes' => 60, 'seats_total' => 5,
    ]);
    fundBooking($this->ws, $this->student, $this->course);

    $booking = $this->postJson("/api/v1/class-sessions/{$session->uuid}/book")->assertCreated();
    $uuid = $booking->json('data.uuid') ?? $booking->json('uuid');

    $this->asGuest();
    $this->deleteJson("/api/v1/bookings/{$uuid}")->assertOk();
});
