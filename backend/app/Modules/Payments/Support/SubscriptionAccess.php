<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Events\SubscriptionEnded;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * Shutting a subscription's access, in exactly one place.
 *
 * Two callers reach it — the nightly expiry and an outright cancellation — and
 * they are the same act with two reasons behind it. Written twice, the pair
 * would be one predicate apart the first time either changes, and the failure
 * direction is silent: a student keeps a month of access they no longer hold,
 * and nothing anywhere reports it.
 *
 * ⚠️ THE PREDICATE IS `(order_id, source = 'subscription')`, AND BOTH HALVES ARE
 * LOAD-BEARING. `EnrollStudent` is `firstOrCreate`, so a student who had ALREADY
 * bought one of the covered courses outright gets their existing, open-ended
 * enrolment back — carrying neither this order id nor this source — and it is
 * that row the two conditions here exist to leave alone. Closing it would
 * revoke, a month later and without a word, access somebody paid for once and
 * for good.
 *
 * ⚠️ AND IT IS `status`, NOT `expires_at`. Nothing in this tree reads
 * `expires_at` as a gate: it is written by 013's retention walk and printed in
 * an export, and that is all. An enrolment left `active` with a date in the past
 * is permanent access sold for a month.
 */
class SubscriptionAccess
{
    /**
     * @return int how many enrolments were actually closed
     *
     * ⚠️ THE EVENT IS FIRED FROM HERE, NOT FROM THE TWO CALLERS. This class
     * exists because expiry and cancellation are one act with two reasons, and
     * its docblock above says what happens when the pair is written twice: they
     * drift one predicate apart and the failure direction is silent. Releasing
     * the seats is part of that same act, so it hangs off the same line — a
     * dispatch added to `ExpireSubscriptionsJob` alone would leave a cancelled
     * subscriber holding next month's seats, and nothing would report it.
     *
     * ⚠️ AND THE COURSE IDS ARE READ BEFORE THE UPDATE. One line later these
     * rows are `expired` and a listener asking «which courses did this close?»
     * finds none — the shape `CancelClassSession` already wrote down for its
     * seat holders.
     *
     * ⚠️ AND NOTHING IS ANNOUNCED WHEN NOTHING CLOSED. A student who bought a
     * course outright keeps that enrolment (the `source` predicate leaves it
     * alone), and a subscription that covered only such courses ends without
     * taking anything away — an event there would ask a listener to release
     * seats the subscription never paid for.
     */
    public static function close(Subscription $subscription): int
    {
        // ⛔ `completed` too: a subscriber who finishes the course keeps it open
        // (`Enrollment::GRANTING_STATUSES`), so closing `active` alone turned
        // finishing early into permanent free access after the month ran out.
        $rows = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $subscription->order_id)
            ->where('source', 'subscription')
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->get(['id', 'course_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $closed = Enrollment::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $rows->pluck('id'))
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->update(['status' => EnrollmentStatus::Expired->value]);

        if ($closed > 0) {
            SubscriptionEnded::dispatch(
                (int) $subscription->workspace_id,
                (int) $subscription->student_user_id,
                array_values(array_unique(array_map(
                    static fn (mixed $id): int => (int) $id,
                    $rows->pluck('course_id')->all(),
                ))),
            );
        }

        return $closed;
    }

    /**
     * Give the cancelled subscription's courses back to a subscription of the
     * same student that is still running today, instead of closing them.
     *
     * ⛔ VERIFIED 2026-09-25: a renewal is activated at APPROVAL, not on its
     * start date, and `EnrollStudent::handOver()` moves the one enrolment row
     * (`order_id`, `expires_at`) to the renewal at that moment. So cancelling a
     * renewal that has not started yet reached `close()` with the row in hand —
     * and the student lost the rest of the month that is paid and still running.
     *
     * The row goes back to the still-running subscription that covers the course
     * (the latest-ending one, if two do), on that subscription's own clock — the
     * row is then exactly what it was before the renewal took it, and the
     * running month's own expiry closes it on the right night. A course no live
     * subscription covers is left for `close()`, as before.
     *
     * @return int how many enrolments were handed back
     */
    public static function handBackToRunning(Subscription $cancelled): int
    {
        $rows = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $cancelled->order_id)
            ->where('source', 'subscription')
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value])
            ->get(['id', 'course_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $running = Subscription::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $cancelled->student_user_id)
            ->whereKeyNot($cancelled->getKey())
            ->liveOn(now())
            ->orderByDesc('effective_ends_on')
            ->get();

        if ($running->isEmpty()) {
            return 0;
        }

        $covered = app(CoveredCourses::class);
        $days = app(SubscriptionDays::class);
        $handedBack = 0;

        foreach ($running as $subscription) {
            $plan = Plan::query()->withoutWorkspaceScope()->find($subscription->plan_id);

            if ($plan === null) {
                continue;
            }

            $courseIds = $covered->coveredCourses($plan)->modelKeys();

            foreach ($rows as $key => $row) {
                if (! in_array((int) $row->course_id, array_map('intval', $courseIds), true)) {
                    continue;
                }

                $handedBack += Enrollment::query()
                    ->withoutWorkspaceScope()
                    ->whereKey($row->getKey())
                    // Conditional: a row somebody else moved in between is theirs.
                    ->where('order_id', $cancelled->order_id)
                    ->update([
                        'order_id' => $subscription->order_id,
                        'expires_at' => $days->endOf(CarbonImmutable::parse($subscription->effective_ends_on)),
                    ]);

                $rows->forget($key);
            }
        }

        return $handedBack;
    }
}
