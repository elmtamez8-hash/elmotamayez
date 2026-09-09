<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Http\Controllers\AttendanceController;
use App\Modules\LiveSessions\Http\Controllers\BookingController;
use App\Modules\LiveSessions\Http\Controllers\BroadcastController;
use App\Modules\LiveSessions\Http\Controllers\ClassSessionController;
use App\Modules\LiveSessions\Http\Controllers\EligibilityController;
use App\Modules\LiveSessions\Http\Controllers\FreezePeriodController;
use App\Modules\LiveSessions\Http\Controllers\PrivateSessionRequestController;
use App\Modules\LiveSessions\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
 * Routes for the LiveSessions module.
 *
 * App\Shared\Modules\Module adds the `/api/v1` prefix and the `api` middleware
 * group automatically — do not repeat either here.
 *
 * Every write carries a NAMED limiter. An inline `throttle:60,1` is banned:
 * ThrottleRequests keys guests on domain|ip with no route in the hash, so every
 * inline limit shares one counter and the strictest wins.
 */

Route::middleware('auth:sanctum')->group(function (): void {
    // The student's own timetable, across every teacher they study with.
    Route::get('/schedule', [ScheduleController::class, 'index']);
    Route::get('/schedule/next', [ScheduleController::class, 'next']);

    /*
    | The guardian's two reads about one child (029 · US3).
    |
    | ⚠️ TWO SEPARATE ROUTES RATHER THAN AN OPTIONAL `?student=` ON THE STUDENT'S
    | OWN, AND THE REASON IS THAT A GUARD WHICH IS USUALLY SKIPPED IS A GUARD
    | NOBODY EXERCISES. `/schedule` above has no parameter by which to ask for
    | anyone else's rows — that absence IS its authorisation — and adding an
    | optional one would turn the commonest request in the product into a path
    | that walks past a permission check on every call. The shape is
    | `GET /billing/children/balance`'s, which drew the same line for money:
    | `?student=` REQUIRED, matched inside the authorised list, and `403` rather
    | than `404` so the refusal says nothing about whether that uuid names a
    | real person.
    |
    | Unthrottled like their siblings: indexed reads behind `auth:sanctum`, and
    | rate-limiting a parent's own dashboard would leave them staring at a
    | screen that cannot say why it is empty.
    */
    Route::get('/schedule/children', [ScheduleController::class, 'children']);
    Route::get('/attendance/children/summary', [AttendanceController::class, 'childSummary']);

    // The next session of ONE course, for the header of its page (FR-015).
    // Deliberately not `/schedule/next?course=`: that one reads the student's
    // own bookings across every teacher, and this header must name the next
    // lesson whether or not a seat has been taken yet.
    Route::get('/courses/{course}/next-session', [ScheduleController::class, 'nextForCourse']);

    /*
     | Every session of one course, for its page's tab (FR-016).
     |
     | ⚠️ NOT `/class-sessions?course=`, WHICH ANSWERS A REAL STUDENT `403`.
     | `ClassSessionPolicy::viewAny()` asks for `SESSIONS_VIEW`, and a student
     | holds no spatie team id — they are a member of no workspace, so the
     | context is null and every `can()` below it is false. The route a student
     | can use is one whose guard is the ENROLMENT.
     */
    Route::get('/courses/{course}/sessions', [ScheduleController::class, 'forCourse']);

    Route::get('/freeze-periods', [FreezePeriodController::class, 'index']);

    Route::get('/class-sessions', [ClassSessionController::class, 'index']);
    Route::get('/class-sessions/{session}', [ClassSessionController::class, 'show']);
    Route::get('/class-sessions/{session}/attendance', [AttendanceController::class, 'index']);

    /*
     | ⚠️ `class-sessions`, NEVER `sessions` (spec 005's rule, still binding).
     | `AuthSession` already owns that word; a third meaning for one word is the
     | line every reader misreads once.
     |
     | ⚠️ AND IT IS A READ ASKED AT EVERY REQUEST (FR-041), not a cached verdict:
     | homework handed in at 9pm opens the session at 9pm, with no sweep in
     | between. No limiter — it is six indexed queries behind `auth:sanctum`,
     | and rate-limiting the explanation of a block would leave a student
     | staring at a screen that cannot tell them why.
     */
    Route::get('/class-sessions/{session}/eligibility', EligibilityController::class);

    /*
     | Names, faces and badges for the uuids the provider echoes into the room.
     | A read, held by every seat holder and by the host — and deliberately NOT
     | the register, which needs `ATTENDANCE_VIEW` and carries marks and notes.
     |
     | ⚠️ THROTTLED NOW, AND THE COMMENT HERE USED TO SAY IT NEED NOT BE —
     | «fetched once when the room opens and refreshed only when somebody new
     | appears». True of one client and false of a class: every client watches
     | every arrival, so a thirty-seat room filling up produced 465 requests in
     | about two minutes, each one a roster join plus a badge read. The client
     | coalesces a burst now; the limiter is the half that does not depend on
     | which build the browser is running.
     |
     | On `throttle:presence` deliberately rather than a fourth bucket: both are
     | per-user room chatter with the same shape, and its 240/min leaves the
     | coalesced client (a handful a minute) more headroom than it can use.
     */
    Route::get('/class-sessions/{session}/participants', [BroadcastController::class, 'participants'])
        ->middleware('throttle:presence');

    /*
    | Q3 · FR-025هـ — what creating the first group of a course has hidden, and
    | the one request that files it. ⚠️ `throttle:sessions`, the existing named
    | limiter for schedule writes: an inline `throttle:N,M` shares one counter
    | with every other inline limit in the application.
    */
    Route::get('/manage/courses/{course}/unassigned-sessions', [ClassSessionController::class, 'unassignedSessions']);

    /*
    | The private-session request (023 · US3). Five routes, all inside the `api`
    | group — which is where `EnsureCurrentWorkspace` runs, and therefore where
    | spatie's team id is pushed in. A route that merely CARRIES the `/api` prefix
    | has none: `/api/broadcasting/auth` was declared with its own middleware list
    | and every teacher was answered 403 on a permission they held, because no
    | team id meant no roles at all.
    |
    | The two reads are unthrottled — they are indexed queries behind
    | `auth:sanctum`, and rate-limiting a student's own list of asks would leave
    | them staring at a screen that cannot say what it is waiting for.
    */
    Route::get('/private-session-requests', [PrivateSessionRequestController::class, 'index']);
    Route::get('/manage/private-session-requests', [PrivateSessionRequestController::class, 'queue']);

    Route::middleware('throttle:sessions')->group(function (): void {
        Route::post('/manage/courses/{course}/assign-sessions', [ClassSessionController::class, 'assignSessions']);

        Route::post('/class-sessions', [ClassSessionController::class, 'store']);
        Route::post('/class-sessions/generate', [ClassSessionController::class, 'generate']);
        Route::put('/class-sessions/{session}', [ClassSessionController::class, 'update']);
        Route::post('/class-sessions/{session}/cancel', [ClassSessionController::class, 'cancel']);

        Route::post('/class-sessions/{session}/book', [BookingController::class, 'store']);
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);

        Route::post('/class-sessions/{session}/join', [BroadcastController::class, 'join']);
        Route::post('/class-sessions/{session}/host/{action}', [BroadcastController::class, 'host']);

        Route::post('/attendances/{attendance}/override', [AttendanceController::class, 'override']);
        Route::post('/class-sessions/{session}/feedback', [AttendanceController::class, 'feedback']);

        /*
        | ⚠️ `{uuid}` IS A STRING, NOT A BOUND MODEL — and the segment is NAMED
        | `uuid` because Laravel fills an untyped controller parameter by matching
        | its NAME to the route segment: `{request}` beside a `Request $request`
        | argument silently leaves `$uuid` empty. `WorkspaceScope` adds no
        | condition when the context is null — which it always is for a student —
        | so an implicit binding here resolves any uuid on the platform, and the
        | policy becomes the only thing between one student and another's
        | calendar. The controller fetches and authorises in adjacent lines.
        */
        Route::post('/courses/{course}/private-session-requests', [PrivateSessionRequestController::class, 'store']);
        Route::delete('/private-session-requests/{uuid}', [PrivateSessionRequestController::class, 'destroy']);
        Route::post('/manage/private-session-requests/{uuid}/decide', [PrivateSessionRequestController::class, 'decide']);

        Route::post('/freeze-periods', [FreezePeriodController::class, 'store']);
        Route::delete('/freeze-periods/{period}', [FreezePeriodController::class, 'destroy']);
    });

    // Its own limiter: one participant sends two a minute, and the ceiling has
    // to leave room for several rooms and reconnection storms without sharing a
    // bucket with the booking endpoints.
    Route::post('/class-sessions/{session}/presence', [BroadcastController::class, 'presence'])
        ->middleware('throttle:presence');
});
