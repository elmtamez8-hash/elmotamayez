<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · SC-002 · FR-004. Running out at one level does not end the session.
|
| ⚠️ THE CONCEPT HERE HAS NO `medium` QUESTIONS AT ALL, which is what makes the
| walk observable: the student earns a promotion out of `easy` to a level that
| does not exist. A fixture with all three difficulties can never reach this code
| path, so a test written on one passes against a build that simply stops.
*/

it('walks to the nearest available level instead of stopping, and says so', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'hard', 'hard', 'hard']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $question = $start['question'];

    // Two correct answers earn a promotion to `medium`, which this bank has none of.
    foreach ([1, 2] as $ignored) {
        $step = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveRightOption($question['question_id'])],
        ])->assertOk()->json('data');

        $question = $step['question'];
    }

    /*
    | ⚠️ THE WALK GOES UP, AND THAT IS NOT A PREFERENCE — IT IS THE CEILING BEING
    | REACHABLE. `easy` and `hard` are equidistant from `medium`; always resolving
    | downward would promote to medium, hand back easy, promote again, hand back
    | easy, for ever — and mastery in this concept could never be earned by any
    | action of the student's, which is the family of defect this repository
    | records as «an item that can never be completed».
    */
    expect($question)->not->toBeNull()
        ->and($question['difficulty'])->toBe('hard')
        // FR-004 out loud. A level that moved in silence is a student handed a
        // difficulty they cannot account for.
        ->and($step['difficulty_note'])->not->toBeNull()
        ->and($step['session']['status'])->toBe('running');
});

it('ends the session cleanly once the concept has nothing left to ask', function (): void {
    // Two questions and nothing else: the third request has nowhere to go.
    $fx = adaptiveFixture(['easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $first = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $start['question']['question_id'],
        // Wrong on purpose: a correct run would reach mastery instead, which is
        // a different ending and would hide this one.
        'option_ids' => [adaptiveWrongOption($start['question']['question_id'])],
    ])->assertOk()->json('data');

    $second = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $first['question']['question_id'],
        'option_ids' => [adaptiveWrongOption($first['question']['question_id'])],
    ])->assertOk()->json('data');

    // Exhaustion is an ENDING, not a failure and not an error: the session closes
    // itself, the paper is sealed, and the student is told why.
    expect($second['question'])->toBeNull()
        ->and($second['session']['status'])->toBe('ended')
        ->and($second['difficulty_note'])->not->toBeNull()
        // Sealed means a score exists to read. A running session reports none.
        ->and($second['session']['score'])->not->toBeNull();
});
