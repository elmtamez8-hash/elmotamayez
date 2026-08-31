<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · SC-001 · FR-002. The ladder moves with the student.
|
| ⚠️ IT RECORDS THE SEQUENCE OF DIFFICULTIES SERVED, ROW BY ROW — never «it went
| down» and «it went up». Those two assertions are both satisfied by a build that
| returns the same difficulty every time and by one that alternates at random;
| only the exact series says the rule is the rule. The series below is derived
| from the shipped settings (promote after 2 correct, demote on any wrong) and
| changes if either moves, which is the point: the numbers are visible in the
| payload, so a test written against them fails loudly rather than drifting.
*/

/**
 * @param  list<array{correct: bool}>  $script
 * @return list<string> the difficulty of each question actually served
 */
function walkAdaptive(array $script, string $sessionUuid, string $firstDifficulty, int $firstQuestion): array
{
    $served = [$firstDifficulty];
    $questionId = $firstQuestion;

    foreach ($script as $turn) {
        $option = $turn['correct'] ? adaptiveRightOption($questionId) : adaptiveWrongOption($questionId);

        $body = test()->postJson("/api/v1/practice/adaptive/{$sessionUuid}/answer", [
            'question_id' => $questionId,
            'option_ids' => [$option],
        ])->assertOk()->json('data');

        if ($body['question'] === null) {
            break;
        }

        $served[] = $body['question']['difficulty'];
        $questionId = $body['question']['question_id'];
    }

    return $served;
}

it('walks up on a run of correct answers and down on the first wrong one', function (): void {
    $fx = adaptiveFixture([
        'easy', 'easy', 'easy', 'easy', 'easy',
        'medium', 'medium', 'medium', 'medium',
        'hard', 'hard', 'hard',
    ]);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    // FR-001: the starting level is announced rather than implied.
    expect($start['session']['difficulty'])->toBe('easy')
        ->and($start['question']['difficulty'])->toBe('easy');

    $served = walkAdaptive(
        [
            ['correct' => true],   // streak 1 — not yet
            ['correct' => true],   // streak 2 — promote
            ['correct' => false],  // demote at once, streak 0
            ['correct' => true],
            ['correct' => true],   // promote again
            ['correct' => true],
            ['correct' => true],   // promote again
        ],
        $start['session']['uuid'],
        $start['question']['difficulty'],
        $start['question']['question_id'],
    );

    expect($served)->toBe(['easy', 'easy', 'medium', 'easy', 'easy', 'medium', 'medium', 'hard']);
});

/*
| ⚠️ ONE WRONG ANSWER IS ENOUGH TO GO DOWN, AND IT TAKES TWO RIGHT ONES TO GO UP.
| The asymmetry is the whole design — a student who has just failed at a level is
| not asked to prove it twice — and a build that used the same threshold in both
| directions would still pass a test that only checked «it moved».
*/
it('demotes on a single wrong answer at the top of a streak', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'medium', 'medium', 'hard']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $served = walkAdaptive(
        [['correct' => true], ['correct' => true], ['correct' => false]],
        $start['session']['uuid'],
        $start['question']['difficulty'],
        $start['question']['question_id'],
    );

    expect($served)->toBe(['easy', 'easy', 'medium', 'easy']);
});

it('tells the student when the level changed, and says nothing when it did not', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'medium', 'medium']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $first = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $start['question']['question_id'],
        'option_ids' => [adaptiveRightOption($start['question']['question_id'])],
    ])->assertOk()->json('data');

    // One correct answer is not a promotion, and a note here would be a screen
    // announcing a move that did not happen.
    expect($first['difficulty_changed'])->toBeFalse()
        ->and($first['difficulty_note'])->toBeNull();

    $second = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $first['question']['question_id'],
        'option_ids' => [adaptiveRightOption($first['question']['question_id'])],
    ])->assertOk()->json('data');

    expect($second['difficulty_changed'])->toBeTrue()
        ->and($second['difficulty_note'])->not->toBeNull();
});
