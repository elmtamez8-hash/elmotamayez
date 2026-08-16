<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeEssayAnswer;
use App\Modules\Assessments\Actions\ReviseGrade;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\GradingRecord;
use App\Modules\Tenancy\Support\Permissions;
use DomainException;

/*
| SC-021. Two people, one paper.
|
| ⚠️ THE ANSWER HERE HAS NO RUBRIC, AND THAT IS THE POINT. The guard originally
| proposed for this was `unique(answer_id, rubric_criterion_id, revision_of)` —
| and both of those columns are nullable, so an essay graded without a mark
| scheme writes NULL into both, and NULL never collides with NULL. The index
| would have been present, correct-looking, and out of service in exactly the
| shape most essays are graded in. The claim on `exam_answers.graded_at` is what
| actually bites.
*/

it('lets the first grader through and refuses the second', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $assistant = $this->addWorkspaceMember($workspace);
    $assistant->givePermissionTo(Permissions::GRADING_PERFORM);

    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 10);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    $action = app(GradeEssayAnswer::class);

    $action->handle($answer, $owner, [['points' => 7]]);

    /*
    | ⚠️ THE SECOND CALL REALLY INSERTS — it is not a fixture pretending to race.
    | The whole failure mode is that both graders' marks land on one answer, so a
    | test that stopped at "the API returns 422" would prove the controller
    | branch and nothing about the table.
    */
    expect(fn (): Answer => $action->handle($answer->fresh(), $assistant, [['points' => 3]]))
        ->toThrow(DomainException::class);

    expect(GradingRecord::query()->where('answer_id', $answer->getKey())->count())->toBe(1)
        ->and((float) $answer->refresh()->points)->toBe(7.0)
        ->and((int) $answer->graded_by)->toBe((int) $owner->getKey());
});

it('refuses the second revision written against the same version', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $second = $this->addWorkspaceMember($workspace);

    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 10);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    app(GradeEssayAnswer::class)->handle($answer, $owner, [['points' => 4]]);

    $revise = app(ReviseGrade::class);

    // Both hold the same loaded model — two teachers who opened the paper
    // together, which is the ordinary way this happens.
    $stale = $answer->fresh();

    $revise->handle($answer->fresh(), $owner, [['points' => 9]], 'أُغفلت فقرة.');

    expect(fn (): Answer => $revise->handle($stale, $second, [['points' => 6]], 'رأيٌ آخر.'))
        ->toThrow(DomainException::class);

    // One revision, not two stacked on top of each other.
    expect((int) $answer->refresh()->grading_version)->toBe(1)
        ->and((float) $answer->points)->toBe(9.0);
});

it('refuses a grader their own paper', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // The teacher sits their own exam — which happens, on a demo account and on
    // the day a teacher checks what the paper looks like from the other side.
    [, $attempt, $essay] = sitEssayExam($workspace, $owner, essayPoints: 10);

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    expect($owner->can('perform', $answer))->toBeFalse();
});
