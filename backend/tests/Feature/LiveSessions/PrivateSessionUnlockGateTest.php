<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Spec 023 · T043 — the homework gate, and the one door it deliberately does not
| guard.
|
| A private hour is what a student asks for BECAUSE they are behind: 008's unlock
| condition (FR-036 → FR-042) governs the next lesson on a COURSE PATH, and
| asking it here would refuse exactly the student the feature exists for. That is
| the user's ruling, recorded in research §8.
|
| ⚠️ BOTH DIRECTIONS, IN ONE FILE. «The private request is accepted» passes just
| as well against a build that deleted the unlock gate from the whole product —
| the group booking beside it is the control that says the gate is still there
| and still bites.
*/

/** @return array{missed: ClassSession, next: ClassSession} */
function absentPreviousSession(array $fx): array
{
    return app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): array {
        UnlockRule::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'requires_attendance' => true,
            'requires_assignment' => false,
            'min_score_pct' => 0,
        ]);

        $make = fn (ClassSessionStatus $status, string $when, int $seats): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'teacher_profile_id' => $fx['profile']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'status' => $status,
            'starts_at' => now()->parse($when),
            'ends_at' => now()->parse($when)->addHour(),
            'seats_total' => $seats,
            'seats_taken' => 0,
        ]);

        /*
        | ⛔ DERIVED FROM THE FIXTURE'S OWN SLOT, NEVER FROM `now()` -- AND THAT
        | IS A BUG THIS FILE ALREADY HAD, WAITING ON THE CLOCK.
        |
        | `privateSessionFixture()` asks for a private hour at next week's
        | 15:00 UTC, and this row was `now() + 3 days` at whatever time of day the
        | suite happened to run. Those land on the SAME DAY for part of every
        | week, and when the run also happens between about 14:00 and 16:00 UTC
        | the two overlap -- so accepting the request is refused with «لديك حصة
        | أخرى في هذا الوقت», a real product rule answering a question the case is
        | not asking. Measured on CI at 14:41 UTC on 2026-09-18, and reproduced
        | locally in the same hour; green every other hour of the day, which is
        | exactly what makes it expensive.
        |
        | 09:00 the day AFTER the private slot: always future, always after the
        | missed one, and it cannot collide with a 15:00 booking whatever day the
        | suite runs.
        */
        $missed = $make(ClassSessionStatus::Completed, '-1 week', 10);
        $next = $make(ClassSessionStatus::Scheduled, $fx['startsAt']->addDay()->setTime(9, 0)->toDateTimeString(), 10);

        attendanceRow($fx['workspace'], $missed, $fx['student'], AttendanceStatus::Absent);

        return ['missed' => $missed, 'next' => $next];
    });
}

it('refuses the ordinary group booking of a student who missed the last lesson', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    $gated = absentPreviousSession($fx);

    $this->asGuest();
    Sanctum::actingAs($fx['student']);

    // The control. Without this the file below would pass against a build that
    // removed the unlock gate from the product entirely.
    $refused = $this->postJson("/api/v1/class-sessions/{$gated['next']->uuid}/book");

    $refused->assertStatus(409);
    expect($refused->json('message'))->toContain('حضور');
});

it('accepts the private-session request of that same student', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    absentPreviousSession($fx);

    $this->asGuest();
    Sanctum::actingAs($fx['student']);

    // FR-025 is asked at SUBMISSION — and what it asks is `refusalReason()`, not
    // `openingRefusal()`. Enrolment, a freeze and the balance are the teacher's
    // to enforce; an unfinished worksheet is the reason for the ask.
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->pending()->firstOrFail();

    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    // And the seat behind the acceptance is not gated either — `claimGrantedSeat`
    // asks the same narrower question, so the refusal cannot arrive at the moment
    // the teacher says yes.
    $this->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", [
        'accept' => true,
    ])->assertOk();

    expect($request->refresh()->status)->toBe(PrivateSessionRequest::ACCEPTED);
});
