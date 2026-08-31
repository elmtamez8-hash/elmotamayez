<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · SC-007 · FR-015 — dropping out and coming back loses nothing.
|
| ⚠️ THE RESUME IS A ROW READ, AND THAT IS WHAT MAKES IT TESTABLE HERE AT ALL. If
| the position lived in the browser there would be nothing on this side to assert
| against, and the requirement would be measurable only by a real disconnection.
| «Come back» below is literally a second `POST /join` — the same call, answering
| with the seat already held — and then the same `GET`.
*/

it('gives a returning participant their place, their score and their answers', function (): void {
    $fx = studyRoomFixture(['easy', 'easy', 'easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 4]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $view = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data');

    expect($view['questions'])->toHaveCount(4);

    // Two answers, one right and one wrong: a resume that restored only the count
    // would pass a test where every answer was correct.
    $first = $view['questions'][0]['question_id'];
    $second = $view['questions'][1]['question_id'];

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
        'question_id' => $first,
        'option_ids' => [adaptiveRightOption($first)],
    ])->assertOk();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
        'question_id' => $second,
        'option_ids' => [adaptiveWrongOption($second)],
    ])->assertOk();

    /*
    | ⚠️ THE «RECONNECTION». A fresh join is what a client does when it comes back
    | on a link it still has, and it must not create a second seat, a second
    | attempt or a second paper.
    */
    $rejoined = $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk()->json();

    expect($rejoined['resumed'])->toBeTrue()
        ->and($rejoined['data']['answered'])->toBe(2)
        ->and($rejoined['data']['score'])->toBe(1);

    $back = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data');

    expect($back['questions'])->toHaveCount(4);

    $answered = collect($back['questions'])->where('answered', true);

    expect($answered)->toHaveCount(2)
        // The MARK comes back, not merely the fact that something was answered:
        // «you got this one wrong» is the half a count cannot carry.
        ->and($answered->firstWhere('question_id', $first)['is_correct'])->toBeTrue()
        ->and($answered->firstWhere('question_id', $second)['is_correct'])->toBeFalse();

    // And the two unanswered ones still hide the mark scheme.
    $pending = collect($back['questions'])->where('answered', false)->first();

    expect($pending)->not->toBeNull()
        ->and(array_key_exists('correct_option_ids', $pending))->toBeFalse()
        ->and(array_key_exists('explanation', $pending))->toBeFalse();
});

it('refuses a second answer to the same question', function (): void {
    /*
    | The other half of «lost nothing»: a client that resends what it already
    | sent — the ordinary shape of a reconnection — must not score twice.
    | `unique(attempt_id, question_id)` is the guard and 409 is the answer.
    */
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 2]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $view = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data');
    $question = $view['questions'][0]['question_id'];

    $body = [
        'question_id' => $question,
        'option_ids' => [adaptiveRightOption($question)],
    ];

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", $body)->assertOk();
    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", $body)->assertStatus(409);

    $after = $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk()->json('data');

    expect($after['answered'])->toBe(1)->and($after['score'])->toBe(1);
});
