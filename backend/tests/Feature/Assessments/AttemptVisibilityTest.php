<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeEssayAnswer;
use App\Modules\Assessments\Actions\ReviseGrade;
use App\Modules\Assessments\Events\ExamFailed;
use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
| FR-027 · FR-032 · NFR-001أ. Who may open a paper, and when a paper is finished.
*/

it('opens a paper for the grader with its rubric and ceilings', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt] = sitEssayExam($workspace, $student, essayPoints: 8);

    Sanctum::actingAs($owner);

    $response = $this->getJson("/api/v1/manage/grading/attempts/{$attempt->uuid}")->assertOk();

    expect($response->json('data.answers'))->toHaveCount(1)
        // The SNAPSHOT's ceiling, which is what the marker is allowed to award
        // against — not the question's current value.
        ->and($response->json('data.answers.0.points_possible'))->toBe(8)
        ->and($response->json('data.answers.0.is_graded'))->toBeFalse()
        // The multiple-choice answer is not in this list: it needs no person.
        ->and($response->json('data.status'))->toBe(Attempt::STATUS_PENDING_GRADING);
});

it('keeps a paper readable after the enrolment behind it ends', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt] = sitEssayExam($workspace, $student);

    // The term ends with the paper still in the queue.
    Enrollment::query()
        ->where('student_user_id', $student->getKey())
        ->update(['status' => 'completed']);

    Sanctum::actingAs($owner);

    /*
    | ⚠️ THIS IS THE BRANCH THAT KEEPS A PAPER FROM BEING ORPHANED. Requiring an
    | ACTIVE enrolment alone would leave every paper handed in on the last day of
    | a term sitting in the queue with nobody entitled to open it — refused, for
    | ever, with the student still waiting for a result.
    */
    $this->getJson("/api/v1/manage/grading/attempts/{$attempt->uuid}")->assertOk();
});

it('refuses a paper belonging to nobody this workspace teaches', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $stranger = $this->addWorkspaceMember($workspace);

    // A paper with no enrolment behind it — a self-generated revision run — by
    // somebody the workspace has never taught.
    $attempt = Attempt::create([
        'workspace_id' => $workspace->getKey(),
        'exam_id' => null,
        'enrollment_id' => null,
        'student_user_id' => $stranger->getKey(),
        'status' => Attempt::STATUS_GRADED,
        'is_practice' => true,
        'score' => 0,
        'max_score' => 100,
        'passed' => false,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    /*
    | ⚠️ `attempts.view_all` USED TO BE THE WHOLE GUARD. NFR-001أ draws the line
    | at an enrolment, not at a row that happens to carry the same workspace id —
    | otherwise a paper a student set themselves is readable by every assistant
    | in an academy the student merely holds a membership of.
    */
    expect($owner->can('view', $attempt))->toBeFalse();

    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/manage/grading/attempts/{$attempt->uuid}")->assertForbidden();
});

it('does not finalize a paper when one of its essays is still unread', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt] = sitEssayExam($workspace, $student, essayPoints: 10, passingScore: 60, essays: 2);

    $answers = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('requires_grading', true)
        ->orderBy('id')
        ->get();

    expect($answers)->toHaveCount(2);

    Event::fake([ExamPassed::class, ExamFailed::class]);

    app(GradeEssayAnswer::class)->handle($answers[0], $owner, [['points' => 6]]);

    expect($attempt->refresh()->status)->toBe(Attempt::STATUS_PENDING_GRADING);

    /*
    | ⚠️ THE REVISION DOOR IS THE ONE THAT USED TO SKIP THE QUEUE. A grader who
    | marks the first essay, spots a slip and corrects it — one click, since the
    | card then reads «عدّل الدرجة» — finalized the whole paper with the second
    | essay unopened. And the damage outlived the moment: `finalizeIfComplete`
    | returns early on an attempt that is no longer pending, so the second
    | essay's marks were written to its own row and NEVER entered the total.
    */
    app(ReviseGrade::class)->handle($answers[0]->fresh(), $owner, [['points' => 9]], 'أُغفلت فقرة.');

    expect($attempt->refresh()->status)->toBe(Attempt::STATUS_PENDING_GRADING)
        ->and($attempt->finalized_at)->toBeNull();

    Event::assertNotDispatched(ExamPassed::class);
    Event::assertNotDispatched(ExamFailed::class);

    app(GradeEssayAnswer::class)->handle($answers[1]->fresh(), $owner, [['points' => 10]]);

    $attempt->refresh();

    // 9 + 10 + the machine's 1, out of 21.
    expect($attempt->status)->toBe(Attempt::STATUS_GRADED)
        ->and(round((float) $attempt->score))->toBe(95.0)
        ->and($attempt->passed)->toBeTrue();

    Event::assertDispatched(ExamPassed::class);
});

it('still re-finalizes when the paper was already finished', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt] = sitEssayExam($workspace, $student, essayPoints: 10, passingScore: 60);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('requires_grading', true)
        ->sole();

    app(GradeEssayAnswer::class)->handle($answer, $owner, [['points' => 2]]);

    expect($attempt->refresh()->passed)->toBeFalse();

    // The other half of the assertion above: the guard must not turn FR-032's
    // recomputation off for the case it was written for.
    app(ReviseGrade::class)->handle($answer->fresh(), $owner, [['points' => 10]], 'أُعيدت القراءة.');

    expect(round((float) $attempt->refresh()->score))->toBe(100.0)
        ->and($attempt->passed)->toBeTrue();
});

it('lets an assistant with the permission open the queue but not revise', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $assistant = $this->addWorkspaceMember($workspace);
    $assistant->givePermissionTo(Permissions::GRADING_PERFORM);
    $assistant->givePermissionTo(Permissions::ATTEMPTS_VIEW_ALL);

    [, $attempt] = sitEssayExam($workspace, $student);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('requires_grading', true)
        ->sole();

    Sanctum::actingAs($assistant);

    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 5]],
    ])->assertOk();

    /*
    | ⚠️ TWO PERMISSIONS, AND THIS IS WHY. Marking is work the teacher delegates;
    | changing a mark already given — and already told to the student — is the
    | teacher's own decision to overrule it. One permission for both would let an
    | assistant quietly rewrite their colleague's marks, or their own.
    */
    $this->patchJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 10]],
        'reason' => 'أعدت النظر.',
    ])->assertForbidden();
});
