<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\ReferralReversed;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Events\PaymentReversed;
use App\Modules\Payments\Events\RefundIssued;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The subscription came back, so the reward goes back (spec 011 · FR-021 · SC-007).
 *
 * ⚠️ TWO TRIGGERS, BECAUSE THERE ARE TWO WAYS MONEY GOES BACK, and the design
 * described the shape of the reversal without naming either — which would have
 * made `SC-007` a criterion with no entrance. `PaymentReversed` carries the
 * order; `RefundIssued` carries a credit transaction and reaches the student
 * through its balance.
 *
 * ⚠️ THE FLIP IS `completed → reversed`, CONDITIONALLY. A `pending` referral has
 * paid nothing, so there is nothing to take back; a `reversed` one has already
 * been settled and a second refund event must not fire a second reversal. The
 * `WHERE` is both the check and the claim.
 *
 * ⚠️ AND `reversed` IS NOT `pending`. Putting the referral back in the queue
 * would let one person subscribe, be paid, cancel, and subscribe again — minting
 * points on a loop off one invitation.
 */
class ReverseReferralAward implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function handle(PaymentReversed|RefundIssued $event): void
    {
        $studentId = $this->studentFor($event);

        if ($studentId === null) {
            return;
        }

        $referral = Referral::query()
            ->where('referred_user_id', $studentId)
            ->where('status', ReferralStatus::Completed->value)
            ->first();

        if ($referral === null) {
            return;
        }

        $claimed = Referral::query()
            ->whereKey($referral->getKey())
            ->where('status', ReferralStatus::Completed->value)
            ->update([
                'status' => ReferralStatus::Reversed->value,
                'reversed_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        // Only the winner announces, so the compensating entries are written
        // exactly once however many times the refund is redelivered.
        ReferralReversed::dispatch(
            (int) $referral->getKey(),
            (int) $referral->referrer_user_id,
            (int) $referral->referred_user_id,
        );
    }

    /**
     * ⚠️ THE JOIN KEY IS THE INVITED STUDENT, NOT THE ORDER. A referral has no
     * order of its own — it is completed by whichever subscription happened to
     * be the first, and `referrals.referred_user_id` is unique, so the person is
     * the key. Reaching for an order id here would need a column that does not
     * exist and would answer nothing for `RefundIssued`, which has no order.
     */
    private function studentFor(PaymentReversed|RefundIssued $event): ?int
    {
        if ($event instanceof PaymentReversed) {
            return (int) $event->order->user_id;
        }

        $balance = $event->transaction->balance()->withoutWorkspaceScope()->first();

        return $balance === null ? null : (int) $balance->student_user_id;
    }
}
