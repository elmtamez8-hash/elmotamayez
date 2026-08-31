<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · FR-023 — a short paper is an ANSWER, not a failure.
|
| ⚠️ THE FIRST DRAFT OF THIS ENDPOINT ANSWERED 422 AND CITED FR-023 FOR IT, WHICH
| SAYS THE OPPOSITE IN AS MANY WORDS: a shortage «must produce an exam with what
| is available, telling the student». `BuildSelfExam.php:86` writes «SHORT IS AN
| ANSWER, NOT A FAILURE» over the same branch. The refusal belongs to ZERO alone,
| where there is nothing to open.
|
| ⚠️ AND `requested_count` IS ASSERTED BESIDE `question_count`. A room built with
| six when twenty were asked for, with nothing saying so, is a room the student did
| not ask for and cannot account for — the shortage has to reach the screen or the
| requirement is only half met.
*/

it('builds the room with what is available and reports both numbers', function (): void {
    $fx = studyRoomFixture(['easy', 'easy', 'easy', 'easy', 'easy', 'easy']);

    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 20,
        'concept' => $fx['concept']->uuid,
    ]);

    expect($room['requested_count'])->toBe(20)
        ->and($room['question_count'])->toBe(6);

    // And the room WORKS at its shorter length: six is the denominator the board
    // counts against, not twenty, or nobody in it could ever finish.
    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    expect($this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data.questions'))
        ->toHaveCount(6);
});

it('refuses only when nothing at all is available', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    /*
    | A difficulty this concept has no question at. The pool answers zero, and
    | zero is the one case with nothing to build — so it is 422 with a sentence
    | rather than a room of no questions, which would be a room nobody can leave
    | by finishing.
    */
    $this->postJson('/api/v1/study-rooms', [
        'teacher' => $fx['workspace']->uuid,
        'concept' => $fx['concept']->uuid,
        'difficulty' => 'hard',
        'question_count' => 5,
        'max_participants' => 5,
        'duration_minutes' => 10,
        'starts_in_minutes' => 0,
    ])->assertStatus(422);
});

it('refuses to open a room for a teacher who has the feature switched off', function (): void {
    /*
    | ⚠️ 403 WITH A CODE, NOT 422. «The teacher has not switched this on» is not a
    | bad request and not something the student can fix by changing a field, so it
    | carries `feature_off` and the screen names the one person who can change it.
    | The switch is read with the RESOLVED workspace id: read from
    | `WorkspaceContext` it would be null for every student, `(int) null === 0`
    | would address the platform default row — which ships OFF — and the feature
    | would be refused to everybody while the panel showed it enabled.
    */
    $fx = studyRoomFixture(['easy', 'easy'], enabled: false);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $this->postJson('/api/v1/study-rooms', [
        'teacher' => $fx['workspace']->uuid,
        'question_count' => 2,
        'max_participants' => 5,
        'duration_minutes' => 10,
        'starts_in_minutes' => 0,
    ])->assertForbidden()->assertJsonPath('code', 'feature_off');
});
