<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\SubscriptionEligibility;
use App\Shared\Contracts\SubscriptionDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| `liveOn($moment)` judged a past moment by TODAY's status (audit 2026-09-27).
|
| A lesson delivered while the month was running and closed after the nightly
| sweep had written `expired` read as «not covered», and was charged a credit; a
| subscription cancelled this afternoon un-covered the lesson it paid for this
| morning. The charge (`coveringSessionFor`) and the teacher's pay
| (`subscriberIdsAmong`) both ask this scope at the session's start, so they are
| asserted together.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->subscription = Subscription::factory()->create([
        'plan_id' => Plan::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'session_type' => ClassSessionType::Group,
            'coverage_type' => PlanCoverage::Workspace,
        ])->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => SubscriptionStatus::Active,
        'starts_on' => CarbonImmutable::today()->subDays(20),
        'ends_on' => CarbonImmutable::today()->addDays(9),
        'effective_ends_on' => CarbonImmutable::today()->addDays(9),
    ]);
});

function liveOnSession(CarbonImmutable $startsAt): ClassSession
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace($test->workspace, fn (): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'teacher_profile_id' => $test->course->teacher_profile_id,
        'course_id' => $test->course->getKey(),
        'type' => ClassSessionType::Group,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
    ]));
}

/** Both readers, asked about one session: [charge covers?, pay covers?]. */
function coveredBothWays(ClassSession $session): array
{
    $studentId = (int) test()->student->getKey();

    return [
        isset(app(SubscriptionEligibility::class)->coveringSessionFor([$studentId], $session)[$studentId]),
        app(SubscriptionDirectory::class)->subscriberIdsAmong(
            [$studentId],
            (int) $session->course_id,
            $session->type->value,
            $session->starts_at,
        ) === [$studentId],
    ];
}

it('covers a lesson a cancelled subscription paid for before it was cancelled', function (): void {
    $taught = liveOnSession(CarbonImmutable::now()->subHours(3));
    $ahead = liveOnSession(CarbonImmutable::now()->addDays(2));

    $this->subscription->forceFill([
        'status' => SubscriptionStatus::Cancelled,
        'cancelled_at' => CarbonImmutable::now()->subHour(),
    ])->save();

    expect(coveredBothWays($taught))->toBe([true, true])
        // A lesson after the cancellation instant is never its to cover.
        ->and(coveredBothWays($ahead))->toBe([false, false]);
});

it('covers a lesson inside the window of a subscription the sweep has since expired', function (): void {
    $taught = liveOnSession(CarbonImmutable::now()->subDays(3));

    $this->subscription->forceFill([
        'status' => SubscriptionStatus::Expired,
        'ends_on' => CarbonImmutable::today()->subDay(),
        'effective_ends_on' => CarbonImmutable::today()->subDay(),
    ])->save();

    expect(coveredBothWays($taught))->toBe([true, true])
        ->and(Subscription::query()->withoutWorkspaceScope()->liveOn(now())->exists())->toBeFalse();
});

it('changes nothing about now', function (): void {
    $this->subscription->forceFill([
        'status' => SubscriptionStatus::Cancelled,
        'cancelled_at' => CarbonImmutable::now()->subSecond(),
    ])->save();

    expect(Subscription::query()->withoutWorkspaceScope()->liveOn(now())->exists())->toBeFalse();
});
