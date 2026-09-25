<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\SubscriptionAccess;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Undo a subscription purchase (FR-029 · and the production caller
 * `PaymentReversed` never had).
 *
 * ⚠️ «CANCEL» HERE MEANS «UNDO», AND THAT IS A CONSEQUENCE OF THE DESIGN RATHER
 * THAN A POLICY CHOICE. Nothing in this product auto-renews: one subscription is
 * one order for one period, so a student who simply does not want another month
 * has nothing to cancel — they stop buying. What is left for this Action is the
 * case where the purchase itself is being taken back, and taking a purchase back
 * without returning the money is not a cancellation, it is a forfeiture. So the
 * access stops and the captured payment is reversed, together, in that order.
 *
 * There is deliberately NO PRORATION. No requirement asks for one, and a
 * part-month refund needs a rule about what a day of unlimited access is worth —
 * a number nobody has approved, computed differently by whoever writes it next.
 * A platform officer who wants to keep part of the money has `AdjustCredits` and
 * the ledger; this Action does the whole thing or none of it.
 *
 * ⚠️ AND IT TOUCHES NOTHING BUT THIS ORDER (FR-029). A student's older debts,
 * their credit balance, and any other subscription are all outside the two
 * statements below — which is the whole of what «cancelling must not wipe an
 * existing prior course's dues» requires, achieved by not writing to them rather
 * than by remembering not to.
 *
 * ⚠️ THE CLAIM IS A CONDITIONAL UPDATE. Two taps on one button would otherwise
 * both read `active`, both proceed, and both call `ReversePayment` — the second
 * throwing from inside a queue worker over a payment the first already reversed,
 * or worse succeeding against a second capture. The loser affects zero rows and
 * is told the decision was already taken.
 */
class CancelSubscription extends Action
{
    use LogsActivity;

    public function __construct(private readonly ReversePayment $reverse) {}

    public function handle(Subscription $subscription, string $reason): Subscription
    {
        /*
        | ⚠️ ONE TRANSACTION, AND THE REASON IS THE LAST STATEMENT IN IT.
        |
        | The claim, the hand-back, the close and the reversal used to be four
        | independent writes. When `ReversePayment` threw — a capture another
        | door reversed a moment earlier, a deadlock — the subscription was
        | already `cancelled` and its enrolments `expired`, the money was still
        | captured, and the retry died on its own claim («غير سارٍ أصلاً»): access
        | taken, payment kept, and no button left that could finish the job.
        |
        | Inside one transaction the conditional UPDATE claim rolls back WITH the
        | failure, so the subscription is `active` again and the same button
        | retries the whole thing. A concurrent second press still loses cleanly:
        | it waits on the row the winner claimed and then updates zero rows.
        | Nothing leaks out of a rolled-back attempt, because every listener on
        | `SubscriptionEnded` and `PaymentReversed` is `ShouldQueueAfterCommit`.
        |
        | A transaction rather than a reordering: reversing first and closing
        | second has the mirror failure — money returned, access still open.
        | `ReverseCourseOrder` makes the same choice for the same pair.
        */
        DB::transaction(function () use ($subscription, $reason): void {
            $claimed = Subscription::query()
                ->withoutWorkspaceScope()
                ->whereKey($subscription->getKey())
                ->where('status', SubscriptionStatus::Active->value)
                ->update([
                    'status' => SubscriptionStatus::Cancelled->value,
                    'cancelled_at' => now(),
                ]);

            if ($claimed === 0) {
                throw new DomainException('هذا الاشتراك غير سارٍ أصلاً.');
            }

            $subscription->refresh();

            // The same shutting the nightly sweep does, through the same file:
            // cancelled and expired are two reasons for one act, and two spellings
            // of it drift silently in the direction of access that never stops.
            //
            // ⛔ BUT FIRST, HAND BACK WHAT A STILL-RUNNING MONTH PAID FOR. A renewal
            // takes the enrolment row at APPROVAL, before its own start date, so
            // cancelling it closed the month the student is still inside (verified
            // 2026-09-25). Only rows no live subscription covers are closed below.
            SubscriptionAccess::handBackToRunning($subscription);
            SubscriptionAccess::close($subscription);

            $this->logActivity('subscription.cancelled', $subscription, [
                'order_id' => $subscription->order_id,
                'reason' => $reason,
            ]);

            /*
            | ⚠️ THE REVERSAL IS WHAT MAKES `PaymentReversed` REACHABLE AT ALL.
            | `ReversePayment` has fired that event since 006 and no file in the tree
            | ever called it — so `ReverseReferralAward`, and every other listener
            | bound to it, was correctly wired to a door with nothing on the other
            | side, and SC-007 was proved only by tests dispatching the event by
            | hand. This is the production entrance.
            |
            | A subscription approved manually always has a captured transaction
            | (`ApproveOrder` mints one). A gateway one may not — a purchase
            | cancelled before the money ever arrived has an order and no capture —
            | and that is not an error: there is nothing to give back.
            */
            $captured = PaymentTransaction::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $subscription->order_id)
                ->where('status', PaymentStatus::Captured->value)
                ->first();

            if ($captured !== null) {
                $this->reverse->handle($captured, $reason);
            }
        });

        return $subscription;
    }
}
