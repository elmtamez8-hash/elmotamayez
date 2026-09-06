<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Events\SubscriptionEnded;
use App\Modules\Payments\Models\Subscription;

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
        $rows = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $subscription->order_id)
            ->where('source', 'subscription')
            ->where('status', EnrollmentStatus::Active->value)
            ->get(['id', 'course_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $closed = Enrollment::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $rows->pluck('id'))
            ->where('status', EnrollmentStatus::Active->value)
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
}
