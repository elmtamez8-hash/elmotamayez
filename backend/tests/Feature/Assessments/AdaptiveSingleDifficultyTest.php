<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\ConceptMastery;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · THE MOST IMPORTANT TEST IN THIS STORY.
|
| ⚠️ A CONCEPT WHOSE QUESTIONS ARE ALL `easy` MUST BE MASTERABLE. Mastery is a run
| of correct answers AT THE CEILING, and the design's first draft read the ceiling
| as the literal `hard`. Under that reading this student could never master this
| concept by ANY action available to them: no `mastered` status, no mastery row,
| no points, and not one error in any log — the concept would sit on their screen
| for ever with a bar nothing they can do will clear.
|
| It is the family of defect this repository already records as «an item that
| enters the denominator and can never be completed», reached from a new door, and
| the spec names this exact case as an edge case in its own words. The fix is
| `ceiling_difficulty`, computed from the concept's own bank at start.
|
| ⚠️ AND IT ASSERTS THE MASTERY ROW, NOT ONLY THE STATUS. A build that flipped the
| status without writing the row would award nothing and show nothing on the
| concept list afterwards, and «status is mastered» alone is green for it.
*/

it('reaches mastery in a concept that has only easy questions', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    // The ceiling is stated, and it is this concept's own — not `hard`.
    expect($start['session']['ceiling_difficulty'])->toBe('easy')
        ->and($start['session']['mastery_after'])->toBe(3);

    $question = $start['question'];
    $step = null;

    foreach (range(1, 3) as $ignored) {
        $step = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveRightOption($question['question_id'])],
        ])->assertOk()->json('data');

        if ($step['question'] === null) {
            break;
        }

        $question = $step['question'];
    }

    expect($step['session']['status'])->toBe('mastered')
        ->and($step['session']['mastered_at'])->not->toBeNull()
        // No further question: the concept is done, and offering another would
        // ask the student to keep clearing a bar they have already cleared.
        ->and($step['question'])->toBeNull();

    $mastery = ConceptMastery::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->where('concept_id', $fx['concept']->getKey())
        ->first();

    expect($mastery)->not->toBeNull()
        // ⚠️ THE CRITERION IS FROZEN INTO THE ROW. Without it nobody can read a
        // year later on WHAT bar this was granted, which is the first question
        // asked after the first time somebody edits the setting.
        ->and($mastery->threshold_correct)->toBe(3)
        ->and($mastery->threshold_difficulty->value)->toBe('easy');
});

it('does not promote past a ceiling the concept does not have', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    // Two correct answers would normally promote. There is nothing above.
    $first = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $start['question']['question_id'],
        'option_ids' => [adaptiveRightOption($start['question']['question_id'])],
    ])->assertOk()->json('data');

    $second = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $first['question']['question_id'],
        'option_ids' => [adaptiveRightOption($first['question']['question_id'])],
    ])->assertOk()->json('data');

    /*
    | ⚠️ AND THE STREAK MUST NOT BE RESET BY A PROMOTION THAT DID NOT HAPPEN. The
    | streak clears on every CHANGE of level; a build that cleared it on every
    | attempted promotion would leave this student oscillating at 1 and 2 and
    | never reaching the mastery threshold of 3 — the same permanent lock by a
    | different route.
    */
    expect($second['session']['difficulty'])->toBe('easy')
        ->and($second['session']['correct_streak'])->toBe(2)
        ->and($second['difficulty_changed'])->toBeFalse();
});
