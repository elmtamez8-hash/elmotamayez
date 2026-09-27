<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\Subscription;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * How much of a cancelled duration subscription goes back to the payer — the
 * UNUSED part only (owner decision 2026-09-27, reversing «a cancellation
 * refunds the whole payment»).
 *
 * THE RULE, IN FULL, because finance and the published terms quote it:
 *
 *  · the plan's length is `ends_on − starts_on + 1` days — both dates
 *    inclusive, the days that were PAID for (a freeze moves the end, never the
 *    length);
 *  · the unused days run from the cancellation date — the platform-zone day it
 *    happened on, which counts as UNUSED because access stops at that moment —
 *    through `effective_ends_on` (the last day, after any freeze extension),
 *    inclusive, and never more than the plan's length;
 *  · a subscription cancelled before its first day (a renewal bought ahead)
 *    has used nothing and is refunded in full;
 *  · refund = paid × unused ÷ length, computed in MINOR UNITS and FLOORED to
 *    the minor unit (the piastre / dirham): integer division, never a float.
 *
 * `paid` is what was actually captured (after any coupon), never the plan's
 * list price.
 *
 * ⚠️ A FREEZE THAT IS STILL AHEAD, OR RUNNING, IS COUNTED AS UNUSED. The extended
 * end includes days the student will spend frozen, so a cancellation before or
 * during a freeze can find more unused days than remain of access — which is
 * why the count is capped at the plan's length: the student is never refunded
 * more than they paid, and the rounding of that edge falls their way.
 *
 * ⚠️ THE TEACHER'S PAY IS NOT TOUCHED. Sessions already delivered were paid to
 * the teacher from `SessionDelivered` (settlement never sees a subscription),
 * and nothing here reverses a unit — the platform keeps the used part of the
 * money, which is what that pay came out of.
 */
final class SubscriptionRefund
{
    public function __construct(private readonly SubscriptionDays $days) {}

    /**
     * @return array{refund_minor: int, unused_days: int, total_days: int, paid_minor: int}
     */
    public function forCancellation(Subscription $subscription, int $paidMinor, ?DateTimeInterface $at = null): array
    {
        $starts = CarbonImmutable::parse($subscription->starts_on->toDateString());
        $ends = CarbonImmutable::parse($subscription->ends_on->toDateString());
        $effectiveEnds = CarbonImmutable::parse($subscription->effective_ends_on->toDateString());
        $today = CarbonImmutable::parse($this->days->dateOf($at ?? CarbonImmutable::now()));

        $total = (int) $starts->diffInDays($ends) + 1;
        $paid = max(0, $paidMinor);

        if ($total <= 0) {
            // A row with no length cannot be apportioned; nothing of it was used.
            return ['refund_minor' => $paid, 'unused_days' => 0, 'total_days' => 0, 'paid_minor' => $paid];
        }

        $from = $today->lessThan($starts) ? $starts : $today;

        $unused = $from->greaterThan($effectiveEnds)
            ? 0
            : min($total, (int) $from->diffInDays($effectiveEnds) + 1);

        return [
            // Floored to the minor unit: integer division, never a float.
            'refund_minor' => intdiv($paid * $unused, $total),
            'unused_days' => $unused,
            'total_days' => $total,
            'paid_minor' => $paid,
        ];
    }

    /**
     * The hours-plan counterpart: paid × unused sessions ÷ sessions bought,
     * floored to the minor unit. Used by `ReverseCreditOrder` for a session-shaped
     * plan; a credit PACKAGE is not a subscription and keeps its full reversal.
     */
    public static function forSessions(int $paidMinor, int $unusedSessions, int $sessionCount): int
    {
        $paid = max(0, $paidMinor);

        if ($sessionCount <= 0) {
            return $paid;
        }

        return intdiv($paid * max(0, min($sessionCount, $unusedSessions)), $sessionCount);
    }
}
