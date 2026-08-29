<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
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
    /** @return int how many enrolments were actually closed */
    public static function close(Subscription $subscription): int
    {
        return Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $subscription->order_id)
            ->where('source', 'subscription')
            ->where('status', EnrollmentStatus::Active->value)
            ->update(['status' => EnrollmentStatus::Expired->value]);
    }
}
