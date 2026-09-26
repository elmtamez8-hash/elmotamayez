<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Models\SessionBooking;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| Spec 023 · T041 — SC-006. The acceptance is the only moment anything exists.
|
| ⚠️ `fakeSessionTimeline()`, NEVER A BARE `Queue::fake()`. A delay runs
| IMMEDIATELY on the `sync` connection, so `CloseClassSessionJob` would fire
| inside the acceptance and close the lesson before anybody could look at it —
| while a bare fake would swallow the QUEUED LISTENERS too, and every count below
| would be a confident assertion about an empty table.
*/

/** @return array{fx: array<string, mixed>, request: PrivateSessionRequest} */
function acceptedPrivateRequest(array $fx, ?CarbonImmutable $at = null): array
{
    Sanctum::actingAs($fx['student']);

    test()->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => ($at ?? $fx['startsAt'])->toIso8601String(),
    ])->assertCreated();

    $request = PrivateSessionRequest::query()
        ->withoutWorkspaceScope()
        ->pending()
        ->where('starts_at', ($at ?? $fx['startsAt'])->format('Y-m-d H:i:s'))
        ->firstOrFail();

    test()->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    test()->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", [
        'accept' => true,
    ])->assertOk();

    return ['fx' => $fx, 'request' => $request->refresh()];
}

it('produces exactly one group, one session, one seat and one settled request', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    ['request' => $request] = acceptedPrivateRequest($fx);

    expect($request->status)->toBe(PrivateSessionRequest::ACCEPTED)
        ->and($request->class_session_id)->not->toBeNull();

    $sessions = ClassSession::query()->withoutWorkspaceScope()->get();
    expect($sessions)->toHaveCount(1);

    $session = $sessions->first();
    expect($session->type)->toBe(ClassSessionType::Individual)
        ->and((int) $session->seats_total)->toBe(1)
        ->and((int) $session->seats_taken)->toBe(1)
        ->and((int) $session->course_id)->toBe((int) $fx['course']->getKey())
        // FR-019أ — never a session outside a group. Left null it would be an
        // «unassigned» session, which `CohortSessionVisibility` offers to the
        // whole course.
        ->and($session->cohort_id)->not->toBeNull()
        ->and((int) $session->duration_minutes)->toBe(45);

    expect(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(1);

    $cohorts = Cohort::query()->withoutWorkspaceScope()->whereNotNull('individual_for_user_id')->get();
    expect($cohorts)->toHaveCount(1);
    expect((int) $cohorts->first()->individual_for_user_id)->toBe((int) $fx['student']->getKey())
        ->and($cohorts->first()->status)->toBe(Cohort::CLOSED)
        ->and((int) $cohorts->first()->capacity)->toBe(1);
});

it('reuses the one group for a second private hour in the same course', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();

    acceptedPrivateRequest($fx);
    acceptedPrivateRequest($fx, $fx['startsAt']->addWeek());

    // SC-006أ. Two lessons, ONE group: the thread, the membership history and the
    // list of hours accumulate in one place rather than scattering one group per
    // week.
    expect(ClassSession::query()->withoutWorkspaceScope()->count())->toBe(2);
    expect(Cohort::query()->withoutWorkspaceScope()->whereNotNull('individual_for_user_id')->count())->toBe(1);

    $cohortIds = ClassSession::query()->withoutWorkspaceScope()->pluck('cohort_id')->unique();
    expect($cohortIds)->toHaveCount(1);
});

it('never opens a membership in the private group', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    acceptedPrivateRequest($fx);

    /*
    | ⚠️ `cohort_memberships` CARRIES `unique(student_user_id, course_id, closed_slot)`
    | — ONE open membership per (student, course). So opening one for the private
    | group would CLOSE the student's weekly group and take away the class they
    | paid for, in exchange for a single private hour. The private session reaches
    | its student through their own booking instead.
    */
    expect(CohortMembership::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a second decision on a request already decided', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    ['request' => $request] = acceptedPrivateRequest($fx);

    $this->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", [
        'accept' => true,
    ])->assertStatus(422);

    // And nothing was produced by the refusal — a second lesson on the teacher's
    // calendar and a second seat out of the student's balance.
    expect(ClassSession::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('demands a written reason before it refuses, and writes nothing without one', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();

    Sanctum::actingAs($fx['student']);
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->firstOrFail();

    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    $this->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", [
        'accept' => false,
    ])->assertStatus(422);

    // Still pending: a refusal that could not be explained did not happen.
    expect($request->refresh()->status)->toBe(PrivateSessionRequest::PENDING);
});

/*
| Found in local E2E (2026-09-26): the student cancelled their granted private
| hour, the booking went but the 1:1 session stayed `scheduled` with no seat —
| the teacher's hour stayed blocked, «احجز» took it back without a request, and
| a new request for the same slot died at the teacher's «قبول» with «لديك حصة
| أخرى». Owner decision: giving the only seat back in time cancels the session.
*/
it('cancels a granted private session when its student cancels in time, and frees the hour', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    ['request' => $request] = acceptedPrivateRequest($fx);

    $booking = SessionBooking::query()->withoutWorkspaceScope()->sole();

    Sanctum::actingAs($fx['student']);
    $this->deleteJson("/api/v1/bookings/{$booking->uuid}")->assertOk();

    $session = ClassSession::query()->withoutWorkspaceScope()->findOrFail($request->class_session_id);
    expect($session->status)->toBe(ClassSessionStatus::Cancelled)
        ->and((int) $session->seats_taken)->toBe(0);

    // «حصصي الخاصة» must say so (2026-09-26): the request stays «accepted» —
    // that is what happened to the REQUEST — so the lesson's own status travels
    // beside it, or the card reads «مقبول» over a called-off hour.
    $this->getJson('/api/v1/private-session-requests')
        ->assertOk()
        ->assertJsonPath('data.0.status', PrivateSessionRequest::ACCEPTED)
        ->assertJsonPath('data.0.class_session_uuid', $session->uuid)
        ->assertJsonPath('data.0.class_session_status', ClassSessionStatus::Cancelled->value);

    // Nobody books a called-off hour back through «احجز».
    $this->postJson("/api/v1/class-sessions/{$session->uuid}/book")
        ->assertStatus(409)
        ->assertJsonPath('message', 'هذه الحصة لم تعد متاحة للحجز.');

    // The same slot can be asked for again, and this time the teacher can grant it.
    ['request' => $again] = acceptedPrivateRequest($fx);

    expect($again->status)->toBe(PrivateSessionRequest::ACCEPTED)
        ->and((int) $again->class_session_id)->not->toBe((int) $session->getKey());
});

it('leaves a private session standing when its student cancels late, because that seat is still charged', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    ['request' => $request] = acceptedPrivateRequest($fx);

    $booking = SessionBooking::query()->withoutWorkspaceScope()->sole();

    // Past the cancellation deadline: the seat and its frozen credit stay.
    $this->travelTo($fx['startsAt']->subHours(2));

    Sanctum::actingAs($fx['student']);
    $this->deleteJson("/api/v1/bookings/{$booking->uuid}")->assertOk();

    $session = ClassSession::query()->withoutWorkspaceScope()->findOrFail($request->class_session_id);
    expect($session->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($booking->refresh()->status)->toBe(BookingStatus::CancelledLate);
});
