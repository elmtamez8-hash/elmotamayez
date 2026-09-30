<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| The three private-session notifications that had no test of their own
| (`EveryNotificationTypeIsTestedTest`): the teacher is asked, and the student is
| answered either way. Driven through the two HTTP doors the product uses.
|
| ⚠️ `fakeSessionTimeline()`, never a bare `Queue::fake()` — every listener here
| is queued, and a bare fake would make each assertion a confident claim about an
| empty table.
*/

/** @param array<string, mixed> $fx */
function privateNoticeRequested(array $fx): PrivateSessionRequest
{
    Sanctum::actingAs($fx['student']);

    test()->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    return PrivateSessionRequest::query()->withoutWorkspaceScope()->pending()->sole();
}

/** @param array<string, mixed> $fx */
function privateNoticeDecided(array $fx, PrivateSessionRequest $request, bool $accept, ?string $reason = null): void
{
    test()->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    test()->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", array_filter([
        'accept' => $accept,
        'decision_reason' => $reason,
    ], fn ($value): bool => $value !== null))->assertOk();
}

beforeEach(function (): void {
    fakeSessionTimeline();
});

it('asks the teacher who wrote the course, not the student', function (): void {
    $fx = privateSessionFixture();

    privateNoticeRequested($fx);

    assertNotifiedOnce($fx['owner'], NotificationType::PrivateSessionRequested);

    expect(wasNotified($fx['student'], NotificationType::PrivateSessionRequested))->toBeFalse();
});

it('tells the student their private session was accepted', function (): void {
    $fx = privateSessionFixture();

    privateNoticeDecided($fx, privateNoticeRequested($fx), accept: true);

    assertNotifiedOnce($fx['student'], NotificationType::PrivateSessionAccepted);

    expect(wasNotified($fx['student'], NotificationType::PrivateSessionRejected))->toBeFalse();
});

it('tells the guardian on the schedule consent when a private session is accepted, and not when it is refused', function (): void {
    /*
    | The acceptance puts a new lesson on the child's timetable — the family's
    | day moves with it. The refusal is a step in a conversation about a lesson
    | that never existed, and stays between the child and the teacher.
    */
    $fx = privateSessionFixture();
    $schedule = guardianOf($fx['student'], [GuardianPermission::Schedule]);
    $paymentsOnly = guardianOf($fx['student'], [GuardianPermission::Payments]);

    privateNoticeDecided($fx, privateNoticeRequested($fx), accept: true);

    $row = assertNotifiedOnce($schedule, NotificationType::PrivateSessionAccepted);

    expect($row->action_url)->toBe('/dashboard?student='.$fx['student']->uuid)
        ->and(wasNotified($paymentsOnly, NotificationType::PrivateSessionAccepted))->toBeFalse();
});

it('keeps a refused private session between the child and the teacher', function (): void {
    $fx = privateSessionFixture();
    $schedule = guardianOf($fx['student'], [GuardianPermission::Schedule]);

    privateNoticeDecided($fx, privateNoticeRequested($fx), accept: false, reason: 'لديّ التزام');

    assertNotifiedOnce($fx['student'], NotificationType::PrivateSessionRejected);

    expect(wasNotified($schedule, NotificationType::PrivateSessionRejected))->toBeFalse();
});

it('tells the student their private session was refused, with the reason', function (): void {
    $fx = privateSessionFixture();

    privateNoticeDecided($fx, privateNoticeRequested($fx), accept: false, reason: 'لديّ التزام في ذلك الوقت');

    $row = assertNotifiedOnce($fx['student'], NotificationType::PrivateSessionRejected);

    expect((string) $row->body)->toContain('لديّ التزام في ذلك الوقت')
        ->and(wasNotified($fx['student'], NotificationType::PrivateSessionAccepted))->toBeFalse();
});
