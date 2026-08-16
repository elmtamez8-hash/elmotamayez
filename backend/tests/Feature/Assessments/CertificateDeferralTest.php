<?php

declare(strict_types=1);

use App\Modules\Assessments\Events\ExamFailed;
use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
| SC-011. The certificate contract waits for the person.
|
| ⚠️ THIS TEST MUST FAIL AGAINST THE PRE-008 CODE, and that is the whole reason
| it exists. `ExamPassed` used to fire at submission, when every question was
| machine-marked and the score at submission WAS the final score. An essay breaks
| that equality: a handed-in paper can be holding half its marks. Firing then
| issues a certificate for half an exam — and `IssueCertificateIfEligible` is
| idempotent, so it will not issue a second one, but nothing withdraws the first.
*/

it('emits no pass or fail while an essay is still waiting on a person', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    Event::fake([ExamPassed::class, ExamFailed::class]);

    // The multiple choice is right and the essay unread: the auto-score alone
    // would clear no threshold worth issuing on, but a FAIL event is just as
    // wrong — it is a verdict on a paper nobody has finished reading.
    [, $attempt] = sitEssayExam($workspace, $student, essayPoints: 10, passingScore: 60);

    expect($attempt->status)->toBe(Attempt::STATUS_PENDING_GRADING);

    Event::assertNotDispatched(ExamPassed::class);
    Event::assertNotDispatched(ExamFailed::class);
});

it('emits the pass only once the essay has been marked', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 10, passingScore: 60);

    Event::fake([ExamPassed::class, ExamFailed::class]);

    Sanctum::actingAs($owner);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 10]],
    ])->assertOk();

    Event::assertDispatched(ExamPassed::class);
    Event::assertNotDispatched(ExamFailed::class);
});

it('emits the fail when the marks do not reach the threshold', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 10, passingScore: 60);

    Event::fake([ExamPassed::class, ExamFailed::class]);

    Sanctum::actingAs($owner);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    // 2 of 10 on the essay plus the machine's 1 of 1 is 3 of 11 — a fail, and
    // the other half of the assertion above: a chain that never fired either
    // event would pass that test just as well.
    $this->postJson("/api/v1/manage/grading/answers/{$answer->uuid}", [
        'marks' => [['points' => 2]],
    ])->assertOk();

    Event::assertDispatched(ExamFailed::class);
    Event::assertNotDispatched(ExamPassed::class);
});
