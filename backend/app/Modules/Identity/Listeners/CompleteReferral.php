<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\ReferralCompleted;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A referral pays when the invited person actually subscribes (spec 011 · FR-019 · SC-006).
 *
 * ⚠️ THE `kind` FILTER IS THE WHOLE GUARD, AND WITHOUT IT THE CHEAPEST THING IN
 * THE STORE COMPLETES A REFERRAL. FR-019 says «an actual approved subscription»;
 * a listener bound to `PaymentApproved` with no filter pays out for a five-riyal
 * notebook — and since a teacher approves their own store sales, that is an open
 * mint. Only `credits` and `subscription` count.
 *
 * ⚠️ BOUND TO BOTH `PaymentApproved` AND `PaymentCaptured`, exactly as the
 * credits mint is. They are two doors on one payment — a manual transfer an
 * operator approves, and a gateway capture — and binding one leaves every
 * referral completed through the other silently pending for ever.
 *
 * ⚠️ THE FLIP IS A CONDITIONAL UPDATE, which makes it both the redelivery guard
 * and the second-purchase guard: a queue retry matches zero rows, and so does
 * the invited student's next renewal. Never a read-then-write.
 *
 * ⚠️ AND THE CAP IS CHECKED BEFORE THE FLIP, NOT AFTER (FR-023). Refusing the
 * COMPLETION is not the same as refusing the payment: a referral left `pending`
 * can be reviewed and paid by hand, while one flipped `completed` with nothing
 * awarded is invisible and — because the flip is one-way — unrepeatable. This is
 * also the reason the catalogue row carries no `daily_cap`; `AwardPoints`
 * returning null past a cap would produce exactly that state.
 *
 * ⚠️ THE CAP'S COUNT-THEN-FLIP IS NOT ATOMIC, and saying so is better than
 * pretending. It cannot be one statement — MySQL refuses a subquery on the table
 * being updated (`ERROR 1093`) — so two simultaneous completions at the ceiling
 * can both pass the count. The exposure is one referral over the cap, once, for
 * a person who arranged two subscriptions in the same second; the flip itself is
 * still atomic, so nothing is ever paid twice for one referral. A number that
 * can be exceeded by one is a different thing from a number that can be
 * exceeded at will.
 */
class CompleteReferral implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function handle(PaymentApproved|PaymentCaptured $event): void
    {
        $order = $event->order;

        if (! in_array($order->kind, [OrderKind::Credits, OrderKind::Subscription], true)) {
            return;
        }

        $referral = Referral::query()
            ->where('referred_user_id', $order->user_id)
            ->where('status', ReferralStatus::Pending->value)
            ->first();

        if ($referral === null) {
            // No invitation, one already paid, or one flagged for review. All
            // three are ordinary and none is an error.
            return;
        }

        if ($this->capReached((int) $referral->referrer_user_id)) {
            // Left `pending` deliberately — see the class docblock.
            return;
        }

        $claimed = Referral::query()
            ->whereKey($referral->getKey())
            ->where('status', ReferralStatus::Pending->value)
            ->update([
                'status' => ReferralStatus::Completed->value,
                'completed_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        // Only the winner announces. Gamification decides what it is worth —
        // nothing in this module names `AwardPoints`, which is Constitution III
        // and the shape every award in this product already takes.
        ReferralCompleted::dispatch(
            (int) $referral->getKey(),
            (int) $referral->referrer_user_id,
            (int) $referral->referred_user_id,
        );
    }

    private function capReached(int $referrerId): bool
    {
        $cap = (int) PlatformSettings::get(
            'referral.max_completed_per_referrer',
            config('referral.max_completed_per_referrer', 20),
        );

        if ($cap <= 0) {
            // Zero is «switched off», not «unlimited». A cap of zero read as no
            // ceiling is the failure direction that costs money.
            return true;
        }

        $completed = Referral::query()
            ->where('referrer_user_id', $referrerId)
            // `reversed` does not count against the ceiling: nothing was kept.
            ->where('status', ReferralStatus::Completed->value)
            ->count();

        return $completed >= $cap;
    }
}
