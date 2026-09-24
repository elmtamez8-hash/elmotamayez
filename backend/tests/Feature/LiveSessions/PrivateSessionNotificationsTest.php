<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\Notifications\Support\NotificationType;
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

it('tells the student their private session was refused, with the reason', function (): void {
    $fx = privateSessionFixture();

    privateNoticeDecided($fx, privateNoticeRequested($fx), accept: false, reason: 'لديّ التزام في ذلك الوقت');

    $row = assertNotifiedOnce($fx['student'], NotificationType::PrivateSessionRejected);

    expect((string) $row->body)->toContain('لديّ التزام في ذلك الوقت')
        ->and(wasNotified($fx['student'], NotificationType::PrivateSessionAccepted))->toBeFalse();
});
