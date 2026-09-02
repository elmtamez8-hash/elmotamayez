<?php

declare(strict_types=1);

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\LiveSessions\Jobs\ExpirePrivateSessionRequestsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Spec 023 · T046 · T047 — SC-005. The deadline, and the teacher who left.
|
| ⚠️ AN EXPIRY THAT SAYS NOTHING IS THE DEFECT. Nothing was held (FR-017), so the
| expiry costs the student a row — and a request that stops being pending in
| silence is indistinguishable from one still waiting. They keep waiting, then
| ask again, and the teacher clears the queue twice.
|
| ⚠️ AND IT MOVES NO MONEY. The whole reason a request holds nothing is so that
| refusing it, withdrawing it and timing it out are all free.
*/

function pendingPrivateRequest(array $fx): PrivateSessionRequest
{
    Sanctum::actingAs($fx['student']);

    test()->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    return PrivateSessionRequest::query()->withoutWorkspaceScope()->firstOrFail();
}

function expiredNotices(array $fx): int
{
    return Notification::query()
        ->where('recipient_user_id', $fx['student']->getKey())
        ->where('type', NotificationType::PrivateSessionExpired->value)
        ->count();
}

it('expires a request past its deadline and tells the student once', function (): void {
    $fx = privateSessionFixture();
    $request = pendingPrivateRequest($fx);

    // Aged by query: `created_at` and `expires_at` are stamped by the raw INSERT,
    // and a fixture that tried to build the row old would be writing columns the
    // Action owns.
    PrivateSessionRequest::query()
        ->withoutWorkspaceScope()
        ->whereKey($request->getKey())
        ->update(['expires_at' => now()->subHour()]);

    app(ExpirePrivateSessionRequestsJob::class)->handle();

    expect($request->refresh()->status)->toBe(PrivateSessionRequest::EXPIRED);
    expect(expiredNotices($fx))->toBe(1);

    // ⚠️ AND NOT AGAIN. The conditional UPDATE is the guard: a second pass finds
    // nothing pending, so «ولا يُعادُ إخبارُه» needs no second predicate anywhere.
    app(ExpirePrivateSessionRequestsJob::class)->handle();

    expect(expiredNotices($fx))->toBe(1);
});

it('leaves a request inside its deadline alone', function (): void {
    $fx = privateSessionFixture();
    $request = pendingPrivateRequest($fx);

    app(ExpirePrivateSessionRequestsJob::class)->handle();

    expect($request->refresh()->status)->toBe(PrivateSessionRequest::PENDING);
    expect(expiredNotices($fx))->toBe(0);
});

it('moves no credit and creates no lesson when it expires', function (): void {
    $fx = privateSessionFixture();
    $request = pendingPrivateRequest($fx);

    $before = app(WorkspaceContext::class)->forWorkspace(
        $fx['workspace'],
        fn (): int => (int) billingBalance($fx['workspace'], $fx['student'], $fx['course'])->remaining_credits,
    );

    PrivateSessionRequest::query()
        ->withoutWorkspaceScope()
        ->whereKey($request->getKey())
        ->update(['expires_at' => now()->subHour()]);

    app(ExpirePrivateSessionRequestsJob::class)->handle();

    $after = app(WorkspaceContext::class)->forWorkspace(
        $fx['workspace'],
        fn (): int => (int) billingBalance($fx['workspace'], $fx['student'], $fx['course'])->remaining_credits,
    );

    expect($after)->toBe($before)
        ->and(ClassSession::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('ends every live request when the teacher leaves the platform', function (): void {
    $fx = privateSessionFixture();
    $request = pendingPrivateRequest($fx);

    $offboarding = app(WorkspaceContext::class)->forWorkspace(
        $fx['workspace'],
        fn (): TeacherOffboarding => TeacherOffboarding::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'teacher_user_id' => $fx['owner']->getKey(),
            'notice_ends_at' => now()->subDay(),
        ]),
    );

    // ⚠️ THE OFFICER'S CONTEXT IS NOT THIS WORKSPACE, WHICH IS THE POINT.
    // `WorkspaceContext::id()` falls back to `users.last_workspace_id` for
    // everybody including platform staff, so a scoped query in the listener would
    // AND the wrong id, match zero rows, and the departure would silently end
    // nothing.
    $this->asGuest();

    event(new TeacherOffboardingCompleted($offboarding));

    // FR-026 — nobody is coming to answer, and the student is told so rather than
    // left watching a decision that cannot arrive.
    expect($request->refresh()->status)->toBe(PrivateSessionRequest::EXPIRED);
    expect(expiredNotices($fx))->toBe(1);
});
