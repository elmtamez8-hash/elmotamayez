<?php

declare(strict_types=1);

use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Courses\Models\Lesson;
use Laravel\Sanctum\Sanctum;

/*
| SC-005. The rates match a hand calculation, to the digit.
|
| ⚠️ TWO OF THESE ARE NEGATIVE CONTROLS, and they are the ones that matter. A
| fixture made only of finished, machine-marked attempts confirms the division
| and nothing else — while the two ways this number goes wrong in production are
| both about which ROWS are counted: a student's own practice run, and an essay
| no person has marked yet.
*/

it('computes a wrong-answer rate that matches the hand calculation', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace);

    // Twenty sittings, seven of them wrong. 7 / 20 = 35.00% and nothing else.
    sitQuestion($workspace, $question, correct: 13, wrong: 7);

    RollUpQuestionStatsJob::dispatch();

    $stat = QuestionStat::query()->where('question_id', $question->getKey())->sole();

    expect($stat->attempts_count)->toBe(20)
        ->and($stat->wrong_count)->toBe(7)
        ->and($stat->wrong_pct)->toBe(35.0);
});

it('leaves a practice run out of the rate', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace);

    sitQuestion($workspace, $question, correct: 8, wrong: 2);
    // The same student revising alone, getting everything wrong. It says
    // something about their evening and nothing about the question.
    sitQuestion($workspace, $question, correct: 0, wrong: 10, practice: true);

    RollUpQuestionStatsJob::dispatch();

    $stat = QuestionStat::query()->where('question_id', $question->getKey())->sole();

    // 2/10, not 12/20. Without the join to `exam_attempts` this reads 60%.
    expect($stat->attempts_count)->toBe(10)
        ->and($stat->wrong_pct)->toBe(20.0);
});

it('does not count an ungraded essay as a wrong answer', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $essay = bankQuestion($workspace, null, ['type' => 'essay']);

    // GradeAttempt writes every essay row `is_correct = false`, because no
    // machine can say otherwise. Counting those reports 100% wrong for every
    // essay in the bank — and permanently, for any nobody has marked.
    sitQuestion($workspace, $essay, correct: 0, wrong: 6, answerOverrides: [
        'requires_grading' => true,
        'graded_at' => null,
    ]);

    RollUpQuestionStatsJob::dispatch();

    expect(QuestionStat::query()->where('question_id', $essay->getKey())->exists())->toBeFalse();
});

it('rolls a concept up overall and again within each lesson', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $lesson = Lesson::factory()->create(['workspace_id' => $workspace->getKey()]);

    $tagged = bankQuestion($workspace, null, ['concept_name' => 'النهايات', 'lesson_id' => $lesson->getKey()]);
    $untagged = bankQuestion($workspace, null, ['concept_name' => 'النهايات']);

    sitQuestion($workspace, $tagged, correct: 4, wrong: 6);
    sitQuestion($workspace, $untagged, correct: 5, wrong: 5);

    RollUpQuestionStatsJob::dispatch();

    $conceptId = $tagged->concept_id;

    $overall = ConceptStat::query()
        ->where('concept_id', $conceptId)
        ->where('lesson_id', ConceptStat::OVERALL)
        ->sole();

    $perLesson = ConceptStat::query()
        ->where('concept_id', $conceptId)
        ->where('lesson_id', $lesson->getKey())
        ->sole();

    // The overall row carries BOTH questions — including the one tagged to no
    // lesson. That is why the two passes group differently.
    expect($overall->attempts_count)->toBe(20)
        ->and($overall->wrong_pct)->toBe(55.0)
        ->and($perLesson->attempts_count)->toBe(10)
        ->and($perLesson->wrong_pct)->toBe(60.0);
});

it('serves the analysis to the teacher without running an aggregate', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace, null, ['content' => 'ما مشتقّة الجيب؟']);
    sitQuestion($workspace, $question, correct: 13, wrong: 7);

    RollUpQuestionStatsJob::dispatch();

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/manage/analytics/questions');

    $response->assertOk();

    expect($response->json('data.0.question.content'))->toBe('ما مشتقّة الجيب؟')
        // Cast, because a whole percentage encodes as `35` rather than `35.0` —
        // the value is right and its JSON type is the encoder's business.
        ->and((float) $response->json('data.0.wrong_pct'))->toBe(35.0)
        ->and($response->json('data.0.has_enough_data'))->toBeTrue()
        ->and($response->json('meta.scope'))->toBe('workspace');
});
