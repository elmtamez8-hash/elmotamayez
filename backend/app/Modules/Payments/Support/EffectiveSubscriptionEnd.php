<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Subscription;
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
     * `ends_on` never moves — it is what the plan sold. Everything a freeze does
     * lands here.
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
     * @return int how many rows actually moved
     */
    public function recomputeForWorkspace(int $workspaceId, ?int $studentUserId = null): int
    {
        $moved = 0;

        Subscription::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('status', SubscriptionStatus::Active->value)
            ->when($studentUserId !== null, fn ($query) => $query->where('student_user_id', $studentUserId))
            // `chunkById`, never `chunk`: nothing narrows under this particular
            // walk today, but the OFFSET form is one added predicate away from
            // skipping a page per page and reporting success.
            ->chunkById(200, function ($subscriptions) use (&$moved): void {
                foreach ($subscriptions as $subscription) {
                    $end = $this->forSubscription($subscription);

                    if (CarbonImmutable::parse($subscription->effective_ends_on)->isSameDay($end)) {
                        continue;
                    }

                    // `forceFill`, because the column is not fillable: it is
                    // derived, and a mass-assignable derived column is a second
                    // way to write an answer only this class may give.
                    $subscription->forceFill(['effective_ends_on' => $end])->save();

                    $moved++;
                }
            });

        return $moved;
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
