<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · FR-016 — a room closes by the clock, and by nothing else.
|
| ⚠️ NOTHING IS DISPATCHED AND NO WORKER RUNS, WHICH IS THE ASSERTION AS MUCH AS
| THE 409 IS. Closure is `now() >= ends_at`, a READ — so the whole family of
| defects a closing job brings falls away with it: a `->delay()` runs IMMEDIATELY
| on the `sync` connection, so the job would shut the room inside the request that
| created it, and a bare `Queue::fake()` would swallow the queued listeners and
| leave every assertion beside it green about nothing.
|
| ⚠️ AND A STORED COLUMN WOULD LIE HERE. `Queue::assertNothingPushed()` below is
| what proves there is nothing to stop: with a stored `status` and a stopped
| worker, a room whose hour has passed would read OPEN by the column and CLOSED by
| the clock, and the two answers would sit side by side on one screen.
*/

it('refuses a join after the hour with no job having run', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);

    // ⚠️ FAKED AFTER THE FIXTURE, NOT BEFORE IT. Building a course and a bank
    // pushes Scout's `MakeSearchable`, which has nothing to do with the claim
    // below — a fake opened earlier would catch it and fail this test for a
    // reason that is not the feature.
    Queue::fake();

    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 2,
        'duration_minutes' => 10,
    ]);

    // ⚠️ NOT ONE JOB WAS PUSHED BY CREATING A ROOM. A build that scheduled a
    // closing job would fail here, before the clock is touched at all.
    Queue::assertNothingPushed();

    expect($room['state'])->toBe('live');

    Carbon::setTestNow(now()->addMinutes(30));

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")
        ->assertStatus(409)
        ->assertJsonPath('code', 'room_closed');

    Carbon::setTestNow();
});

it('reports the room as closed from the clock alone', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 2,
        'duration_minutes' => 10,
    ]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    expect($this->getJson("/api/v1/study-rooms/{$room['uuid']}")->json('data.room.state'))->toBe('live');

    Carbon::setTestNow(now()->addMinutes(30));

    $closed = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data.room');

    // The label travels with the value: the screen must not re-derive a state
    // whose whole point is that it belongs to the server's clock.
    expect($closed['state'])->toBe('closed')
        ->and($closed['state_label'])->toBe('انتهت');

    Carbon::setTestNow();
});

it('refuses a room whose start has not been reached only after it ends, never before', function (): void {
    /*
    | The third state exists so an invitation can be sent before the clock runs.
    | A room that is `pending` is OPEN — refusing a join there would make the
    | `starts_in_minutes` window a window in which nobody can arrive.
    */
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 2,
        'starts_in_minutes' => 5,
    ]);

    expect($room['state'])->toBe('pending');

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();
});
