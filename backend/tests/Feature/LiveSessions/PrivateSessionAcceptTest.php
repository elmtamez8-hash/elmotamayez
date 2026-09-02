<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
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
