<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · FR-014 — who may listen to a room's board.
|
| ⚠️ THE GUARD IS A PARTICIPATION ROW, NOT ELIGIBILITY, AND THAT DISTINCTION IS
| THE WHOLE TEST. «May join» and «is inside» are two questions: authorised on the
| first, any eligible student holding the invite uuid subscribes and reads every
| name and score in the room — without joining it, and without appearing to
| anybody in it.
|
| ⚠️ AND IT IS MEASURED THROUGH THE REAL `/api/broadcasting/auth`. That route is
| registered by `withBroadcasting()`, whose middleware list REPLACES the `api`
| group rather than extending it — which is how every private subscription in this
| product was refused in production while the suite was green. A test that called
| the callback directly would prove the logic and never the request.
*/

it('refuses a student who is eligible but has not joined', function (): void {
    $fx = studyRoomFixture();
    $room = openStudyRoom($fx['student'], $fx['workspace']);

    // Enrolled in the same course, holding the uuid, and would be admitted by
    // `POST /join` this second — which is exactly the person a guard written on
    // eligibility lets listen in.
    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    subscribeToChannel("private-study-room-board.{$room['uuid']}")->assertForbidden();
});

it('admits a participant to their own room and nobody else to it', function (): void {
    $fx = studyRoomFixture();
    $room = openStudyRoom($fx['student'], $fx['workspace']);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    // ⚠️ THE POSITIVE CONTROL. Without it the refusal above is also satisfied by a
    // channel definition that was never registered at all — which answers 403 to
    // everybody and looks identical.
    subscribeToChannel("private-study-room-board.{$room['uuid']}")->assertOk();

    // And a second room they are not in stays shut, on the same connection.
    $elsewhere = studyRoomFixture();
    $other = openStudyRoom($elsewhere['student'], $elsewhere['workspace']);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    subscribeToChannel("private-study-room-board.{$other['uuid']}")->assertForbidden();
});

it('refuses a channel for a room uuid that names nothing', function (): void {
    $fx = studyRoomFixture();

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    subscribeToChannel('private-study-room-board.'.fake()->uuid())->assertForbidden();
});
