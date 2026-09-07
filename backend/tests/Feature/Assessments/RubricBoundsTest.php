<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeEssayAnswer;
use App\Modules\Assessments\Actions\SaveRubric;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\RubricCriterion;
use Laravel\Sanctum\Sanctum;

/*
| SC-010 · FR-028. No mark scheme worth more than its question.
*/

it('refuses criteria adding up to more than the question is worth', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $essay = bankQuestion($workspace, null, [
        'type' => 'essay',
        'points' => 5,
        'content' => 'اشرح.',
    ]);

    expect(fn () => app(SaveRubric::class)->handle($essay, [
        ['label' => 'المحتوى', 'max_points' => 3],
        ['label' => 'اللغة', 'max_points' => 3],
    ]))->toThrow(DomainException::class);

    // ⚠️ NOTHING WAS WRITTEN. A rule that rejects the request after inserting
    // half the rows leaves a scheme worth more than the question behind it.
    expect(RubricCriterion::query()->where('question_id', $essay->getKey())->count())->toBe(0);

    // And the boundary itself passes — a rule that refused the exact total would
    // be just as wrong, and this is the assertion that catches an off-by-one.
    app(SaveRubric::class)->handle($essay, [
        ['label' => 'المحتوى', 'max_points' => 2.5],
        ['label' => 'اللغة', 'max_points' => 2.5],
    ]);

    expect(RubricCriterion::query()->where('question_id', $essay->getKey())->count())->toBe(2);
});

it('refuses a rubric on a question no person grades', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $mcq = practiceQuestion($workspace, 'واحدٌ زائد واحد؟');

    expect(fn () => app(SaveRubric::class)->handle($mcq, [
        ['label' => 'المحتوى', 'max_points' => 1],
    ]))->toThrow(DomainException::class);
});

it('refuses a mark above what its criterion is worth', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 5);

    app(SaveRubric::class)->handle($essay, [
        ['label' => 'المحتوى', 'max_points' => 2.5],
        ['label' => 'اللغة', 'max_points' => 2.5],
    ]);

    $criteria = RubricCriterion::query()->where('question_id', $essay->getKey())->orderBy('id')->get();

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    expect(fn () => app(GradeEssayAnswer::class)->handle($answer, $owner, [
        ['criterion_id' => (int) $criteria[0]->getKey(), 'points' => 4],
    ]))->toThrow(DomainException::class);

    // ⚠️ AND THE CLAIM WAS NOT SPENT. A refusal that had already stamped
    // `graded_at` would leave the answer unmarkable for ever — refused on the
    // way in, and refused on every retry as "already graded".
    expect($answer->refresh()->graded_at)->toBeNull();
});

it('refuses a rubric once somebody has graded against it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [, $attempt, $essay] = sitEssayExam($workspace, $student, essayPoints: 5);

    app(SaveRubric::class)->handle($essay, [['label' => 'المحتوى', 'max_points' => 5]]);

    $criterion = RubricCriterion::query()->where('question_id', $essay->getKey())->sole();

    $answer = Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('question_id', $essay->getKey())
        ->sole();

    app(GradeEssayAnswer::class)->handle($answer, $owner, [
        ['criterion_id' => (int) $criterion->getKey(), 'points' => 5],
    ]);

    /*
    | ⚠️ FROZEN, AND THE REFUSAL IS THE HONEST ANSWER. Replacing the set would
    | leave the record of that 5/5 pointing at a criterion that no longer exists
    | — a teacher's own note of WHY they awarded it, erased by a typo fix.
    */
    expect(fn () => app(SaveRubric::class)->handle($essay, [['label' => 'المضمون', 'max_points' => 5]]))
        ->toThrow(DomainException::class);
});

it('answers 422 rather than an exception on the route', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $essay = bankQuestion($workspace, null, ['type' => 'essay', 'points' => 5, 'content' => 'اشرح.']);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/manage/bank/questions/{$essay->uuid}/rubric", [
        'criteria' => [
            ['label' => 'المحتوى', 'max_points' => 4],
            ['label' => 'اللغة', 'max_points' => 4],
        ],
    ])->assertStatus(422);
});
