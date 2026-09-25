<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Jobs\ClaimSubscriptionSeatsJob;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Shared\Contracts\CohortDirectory;
use Carbon\CarbonImmutable;

/**
 * What a freeze does to a subscription's end date (T095 · FR-031).
 *
 * ⚠️ THE ANSWER IS A COLUMN, AND THIS IS THE ONLY THING ALLOWED TO WRITE IT.
 * Computing it on read is broken twice over: it is a `freeze_periods` query per
 * row on every screen that prints an end date, and the nightly sweep's predicate
 * cannot be written in SQL at all — so the read path would consider a
 * subscription alive while the job expired it, exactly one freeze-length early.
 *
 * ⚠️ AND IT IS RECOMPUTED AT FOUR MOMENTS, NOT ONE. Creating a freeze is the
 * obvious one; editing it, DELETING it (or the extension outlives the reason for
 * it), and activating a subscription inside a freeze that is already running (or
 * it is born without the extension it is owed) are the three that get forgotten.
 * The first three are covered structurally rather than by remembering:
 * `FreezePeriod::booted()` announces all of them, so a fourth write path added
 * tomorrow is covered the day it lands.
 *
 * This does not break «a freeze period is read, never written to» (005 · R11).
 * What is written is the SUBSCRIPTION; the period stays the source of truth, and
 * that is why nothing here can fail halfway in a way that needs undoing.
 */
class EffectiveSubscriptionEnd
{
    public function __construct(
        private readonly SubscriptionDays $days,
        private readonly PlanLineage $lineage,
        private readonly CohortDirectory $cohorts,
    ) {}

    /**
     * How many times the extension may be re-measured against itself.
     *
     * ⚠️ THE EXTENSION CAN LAND INSIDE ANOTHER FREEZE. A single pass — «count
     * frozen days between start and end, add them» — gives a student who was
     * frozen in July an end date sitting in the middle of the August freeze, and
     * silently charges them those days too. So the window is re-measured until it
     * stops moving. It is monotone (each pass only extends) and bounded by the
     * total frozen days, so it converges in about one pass per distinct freeze;
     * the ceiling is a guard against a pathological calendar, not the mechanism.
     */
    private const PASSES = 20;

    /**
     * The date this subscription's access actually runs to.
     *
     * The LENGTH of `starts_on`..`ends_on` never moves — it is what the plan
     * sold. Everything a freeze does to this subscription lands on
     * `effective_ends_on`; the window itself moves only when a renewal is
     * re-dated behind a month a freeze extended ({@see self::redateChain()}).
     */
    public function forSubscription(Subscription $subscription): CarbonImmutable
    {
        $starts = CarbonImmutable::parse($subscription->starts_on)->startOfDay();
        $sold = CarbonImmutable::parse($subscription->ends_on)->startOfDay();

        $frozen = $this->frozenDays(
            (int) $subscription->workspace_id,
            (int) $subscription->student_user_id,
            $starts,
            // A generous horizon: the extension cannot exceed the number of
            // frozen days, and reading a year past the sale is one indexed range
            // scan whatever the answer turns out to be.
            $sold->addYear(),
        );

        if ($frozen === []) {
            return $sold;
        }

        $end = $sold;

        for ($pass = 0; $pass < self::PASSES; $pass++) {
            $days = 0;

            foreach ($frozen as $day => $_) {
                if ($day >= $starts->toDateString() && $day <= $end->toDateString()) {
                    $days++;
                }
            }

            $next = $sold->addDays($days);

            if ($next->equalTo($end)) {
                return $end;
            }

            $end = $next;
        }

        return $end;
    }

    /**
     * Rewrite `effective_ends_on` for every live subscription a freeze touches.
     *
     * `$studentUserId` narrows it to one person when the period that changed was
     * theirs alone; null means the freeze covers the whole workspace, so every
     * live subscription in it is re-measured.
     *
     * ⚠️ EXPIRED AND CANCELLED ROWS ARE LEFT ALONE ON PURPOSE. Extending a
     * subscription that has already ended would revive access somebody was told
     * had stopped, days or months later, with no notification and no order behind
     * it. A freeze declared today is about the time still being sold.
     *
     * ⛔ AND A RENEWAL ALREADY BOUGHT MOVES WITH THE MONTH BEFORE IT. A renewal is
     * dated at approval from the end of the running month (`ActivateSubscription::
     * claim()`), and until this pass that date was never looked at again: a week
     * of freeze declared inside the first month pushed its end a week INTO the
     * renewal, the two overlapped for seven days, and access still stopped on the
     * renewal's original date — the frozen week was simply lost. So after every
     * end is re-measured, each student's chain is walked again
     * ({@see self::redateChain()}), and a lift walks it back the same way.
     *
     * @return int how many rows actually moved
     */
    public function recomputeForWorkspace(int $workspaceId, ?int $studentUserId = null): int
    {
        $moved = 0;
        $students = [];

        Subscription::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('status', SubscriptionStatus::Active->value)
            ->when($studentUserId !== null, fn ($query) => $query->where('student_user_id', $studentUserId))
            // `chunkById`, never `chunk`: nothing narrows under this particular
            // walk today, but the OFFSET form is one added predicate away from
            // skipping a page per page and reporting success.
            ->chunkById(200, function ($subscriptions) use (&$moved, &$students): void {
                foreach ($subscriptions as $subscription) {
                    $students[(int) $subscription->student_user_id] = true;

                    if ($this->remeasure($subscription)) {
                        $moved++;
                    }
                }
            });

        // A second pass, never inside the walk above: shifting one renewal moves
        // a row that walk may already have fetched, and a chain of three would
        // propagate through a stale copy.
        foreach (array_keys($students) as $student) {
            $moved += $this->redateChain($workspaceId, $student);
        }

        return $moved;
    }

    /**
     * Move a student's renewals so each one starts the day after the one before
     * it ends — the rule `ActivateSubscription::claim()` dated them by, asked
     * again now that an end has moved.
     *
     * The chain is per plan LINEAGE (a plan and every plan it replaced), exactly
     * as at approval: a month of physics does not move because a month of maths
     * was frozen. Within one lineage the rows are taken in starting order and
     * each is expected at `max(latest end so far + 1, today)` — the same
     * `max()` the approval takes, so a month that has already run out leaves
     * the next one starting today, never in the past.
     *
     * ⚠️ ONLY A RENEWAL THAT HAS NOT STARTED YET MOVES. One already running is
     * the month the student is in; re-dating it would move access they are
     * using, and a freeze that reaches back behind it was about time that is
     * already spent.
     *
     * ⚠️ THE WINDOW MOVES WHOLE. `starts_on` and `ends_on` shift by the same
     * number of days, so the length the plan sold never changes; the freeze's
     * own extension still lands on `effective_ends_on` alone, re-measured
     * against the new window. Idempotent: a chain already in place moves nothing.
     *
     * @return int how many renewals were re-dated
     */
    private function redateChain(int $workspaceId, int $studentUserId): int
    {
        $today = $this->days->today();

        $subscriptions = Subscription::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $studentUserId)
            ->where('status', SubscriptionStatus::Active->value)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        if ($subscriptions->count() < 2) {
            return 0;
        }

        /** @var array<int, list<int>> $lineages */
        $lineages = [];
        /** @var array<int, CarbonImmutable> $latestEndOf lineage root => latest effective end */
        $latestEndOf = [];
        $moved = 0;

        foreach ($subscriptions as $subscription) {
            $planId = (int) $subscription->plan_id;

            if (! isset($lineages[$planId])) {
                $plan = Plan::query()->withoutWorkspaceScope()->find($planId);
                $lineages[$planId] = $plan === null ? [$planId] : $this->lineage->of($plan);
            }

            // The OLDEST ancestor names the chain: a renewal onto a repriced plan
            // and the month on the plan it replaced share it.
            $root = $lineages[$planId][count($lineages[$planId]) - 1];
            $previousEnd = $latestEndOf[$root] ?? null;

            $starts = CarbonImmutable::parse($subscription->starts_on)->startOfDay();

            if ($previousEnd !== null && $starts->greaterThan($today)) {
                $expected = $previousEnd->addDay()->max($today);
                $shift = (int) $starts->diffInDays($expected, false);

                if ($shift !== 0) {
                    $subscription->forceFill([
                        'starts_on' => $expected,
                        'ends_on' => CarbonImmutable::parse($subscription->ends_on)->startOfDay()->addDays($shift),
                    ])->save();

                    $this->remeasure($subscription, force: true);

                    $moved++;
                }
            }

            $end = CarbonImmutable::parse($subscription->effective_ends_on)->startOfDay();
            $latestEndOf[$root] = $previousEnd === null ? $end : $previousEnd->max($end);
        }

        return $moved;
    }

    /**
     * Re-measure one subscription's end and carry it to what hangs off it.
     *
     * Two things follow the date, or they print and claim the old one:
     *
     *   · the enrolment this subscription currently holds (`order_id`, source
     *     `subscription`) gets `expires_at` = the last instant of the new end,
     *     in the platform's calendar — the renewal took that row over at its
     *     approval (`EnrollStudent::handOver()`), so it is the renewal's row;
     *   · when the end moved LATER on a group subscription, the seats are
     *     claimed again up to the new end (`ClaimSubscriptionSeatsJob` is
     *     re-runnable and takes nothing twice) — spec 027's «the extension days
     *     are booked too». A move EARLIER needs no second path: the seats past
     *     the new end are released when this subscription expires, which is
     *     before those sessions happen.
     *
     * @param  bool  $force  carry the date even when the end did not move — the
     *                       window itself moved, and the enrolment may be on
     *                       the old one
     * @return bool whether the end moved
     */
    private function remeasure(Subscription $subscription, bool $force = false): bool
    {
        $before = CarbonImmutable::parse($subscription->effective_ends_on)->startOfDay();
        $end = $this->forSubscription($subscription);
        $moved = ! $before->isSameDay($end);

        if (! $moved && ! $force) {
            return false;
        }

        if ($moved) {
            // `forceFill`, because the column is not fillable: it is derived,
            // and a mass-assignable derived column is a second way to write an
            // answer only this class may give.
            $subscription->forceFill(['effective_ends_on' => $end])->save();
        }

        Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $subscription->order_id)
            ->where('source', 'subscription')
            ->whereIn('status', Enrollment::GRANTING_STATUSES)
            ->update(['expires_at' => $this->days->endOf($end)]);

        if ($end->greaterThan($before)) {
            $this->reclaimSeats($subscription, $end);
        }

        return $moved;
    }

    /**
     * The group's seats up to the new end, for a subscription bought into one.
     *
     * Only while the student is still in the group the order named: a student
     * moved elsewhere since has their seats claimed for that group by whoever
     * moved them, and `ActivateSubscription::joinCohort()` asks the same.
     */
    private function reclaimSeats(Subscription $subscription, CarbonImmutable $end): void
    {
        $order = Order::query()->withoutWorkspaceScope()->find($subscription->order_id);
        $intent = $order === null ? null : SubscriptionIntent::fromOrder($order);

        if ($order === null || $intent === null || ! $intent->isCohort() || $intent->cohortUuid === null) {
            return;
        }

        $described = $this->cohorts->describeGroupCohort($intent->cohortUuid);
        $student = User::query()->find($subscription->student_user_id);

        if ($described === null || $student === null) {
            return;
        }

        if ($this->cohorts->openMembershipCohortId($student, (int) $described['course_id']) !== (int) $described['id']) {
            return;
        }

        ClaimSubscriptionSeatsJob::dispatch(
            (int) $described['workspace_id'],
            (int) $student->getKey(),
            (int) $described['course_id'],
            (int) $described['id'],
            $end->toDateString(),
        )->afterCommit();
    }

    /**
     * Every frozen calendar day, as a set.
     *
     * ⚠️ A SET, NOT A SUM OF LENGTHS. Two overlapping periods — a workspace-wide
     * holiday and one student's own suspension across the same week — would be
     * counted twice by `SUM(datediff)`, handing that student a fortnight for a
     * week of freeze. Days de-duplicate themselves.
     *
     * @return array<string, true>
     */
    private function frozenDays(
        int $workspaceId,
        int $studentUserId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $periods = FreezePeriod::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            // Null covers every student of this teacher; a value covers one.
            // Grouped, because a flat `orWhere` here would split the workspace
            // condition off the whole predicate and pick up other teachers'
            // holidays.
            ->where(fn ($query) => $query
                ->whereNull('student_user_id')
                ->orWhere('student_user_id', $studentUserId))
            // Plain comparisons, never `whereDate()`: a function around the
            // column throws away `(workspace_id, starts_on, ends_on)`, and this
            // is asked for every subscription in a workspace at once.
            ->where('starts_on', '<', $to->addDay()->toDateString())
            ->where('ends_on', '>=', $from->toDateString())
            ->get(['starts_on', 'ends_on']);

        $days = [];

        foreach ($periods as $period) {
            $day = CarbonImmutable::parse($period->starts_on)->startOfDay();
            $last = CarbonImmutable::parse($period->ends_on)->startOfDay();

            // Clipped to the window we were asked about, so a decade-long
            // period declared by mistake does not walk a decade of days.
            if ($day->lessThan($from)) {
                $day = $from;
            }

            if ($last->greaterThan($to)) {
                $last = $to;
            }

            while ($day->lessThanOrEqualTo($last)) {
                $days[$day->toDateString()] = true;
                $day = $day->addDay();
            }
        }

        return $days;
    }
}
