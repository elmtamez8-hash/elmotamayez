<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Events\ReferralReversed;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Support\ReferralStatus;
use App\Modules\Payments\Events\PaymentReversed;
use App\Modules\Payments\Events\RefundIssued;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * The payment that completed the referral came back, so the reward goes back
 * (spec 011 · FR-021 · SC-007 · owner decision 2026-09-25).
 *
 * ⛔ ONLY THE ORDER THAT COMPLETED IT. This used to key on the invited PERSON:
 * any `PaymentReversed` of theirs — an unrelated course order refunded months
 * later — took the inviter's points back, for a subscription that is still paid
 * and still running. `CompleteReferral` now records the order it completed on
 * (`referrals.completing_order_id`), and that order is the only one whose
 * reversal undoes the award. A referral whose completing order is unknown (a
 * legacy row the backfill could not attribute without guessing) is reversed by
 * NO order: guessing is the defect being removed.
 *
 * ⚠️ TWO TRIGGERS, BECAUSE THERE ARE TWO WAYS MONEY GOES BACK. `PaymentReversed`
 * carries the order and is compared directly. `RefundIssued` carries a credit
 * transaction and no order at all — it is a staff refund of CREDITS — so it
 * counts only when it hands back credits from the very balance the completing
 * order filled. A refund of some other course's credits is not the completing
 * payment coming back, whoever it belongs to.
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
class ReverseReferralAward implements ShouldQueueAfterCommit
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
            ->whereNotNull('completing_order_id')
            ->first();

        if ($referral === null || ! $this->isTheCompletingPayment($event, (int) $referral->completing_order_id)) {
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
     * Whether what came back is the payment that completed the referral.
     *
     * A credit refund names no order, so it is attributed through the balance
     * the completing order credited — a credit purchase (`credit_purchases`) or
     * an hours plan posted straight into the ledger by `ActivateSubscription`.
     * A completing monthly subscription credits no balance at all, so no credit
     * refund can be it; only reversing that order's payment is.
     */
    private function isTheCompletingPayment(PaymentReversed|RefundIssued $event, int $completingOrderId): bool
    {
        if ($event instanceof PaymentReversed) {
            return (int) $event->order->getKey() === $completingOrderId;
        }

        $balanceId = (int) $event->transaction->credit_balance_id;

        return CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $completingOrderId)
            ->where('credit_balance_id', $balanceId)
            ->exists()
            || CreditTransaction::query()
                ->withoutWorkspaceScope()
                ->where('credit_balance_id', $balanceId)
                ->where('source_type', ActivateSubscription::CREDIT_SOURCE_TYPE)
                ->where('source_id', $completingOrderId)
                ->exists();
    }

    /**
     * The invited student, so the one referral they can have is found — the
     * order comparison above then decides whether this payment is the one.
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
