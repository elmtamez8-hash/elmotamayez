<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Reversing a CREDIT sale — a package of credits, or a session-pack plan sold
 * by the hour — the money goes back and the credits it bought that are still
 * unspent go with it.
 *
 * ⛔ THESE TWO SALES HAD NO WAY BACK. `ReverseCourseOrder` refuses every kind
 * but a course; `CancelSubscription` needs a `subscriptions` row, and an hours
 * plan writes none (`ActivateSubscription::activateSessionPlan()`); and
 * `AdjustCredits` has no screen. A bank refund of either left the payment
 * `captured`, the order `approved`, and the credits spendable.
 *
 * One transaction, in the order `ReverseCourseOrder` takes, for its reasons:
 *
 *   1) the ORDER is claimed `approved → cancelled` by a conditional UPDATE — two
 *      presses both read `approved`, and the loser affects zero rows;
 *   2) the unspent credits of THIS purchase are taken back from ITS lot — the
 *      lot is claimed first and the entry posted with `drawsFromLots: false`,
 *      the rule an expiry follows (billing.md): a plain negative movement would
 *      let the drawer take soonest-expiring credits from OTHER purchases, and
 *      the student would lose credits they paid for separately;
 *   3) then `ReversePayment`, whose `PaymentReversed` tells the student and
 *      reverses a referral award. So no `RefundIssued` is fired from here — that
 *      listener takes both events, and two would reverse the award twice.
 *
 * ⚠️ CONSUMED CREDITS STAY CONSUMED. What a lot still holds is by construction
 * what was never spent — a session already charged drew from it, and a debt the
 * purchase paid off opened it smaller (`CreditLedger::openLot()`). Those were
 * sessions delivered, and the teacher has earned their fee for them.
 *
 * ⚠️ A CREDIT FROZEN FOR A BOOKED SEAT IS NOT CONSUMED, so it is taken back too:
 * `held_credits` may then exceed what remains, and the seat is charged at
 * delivery like any other — the floor never refuses recording a debt.
 */
class ReverseCreditOrder extends Action
{
    use LogsActivity;

    /**
     * The idempotency key's type half for the clawback — ≤ 32 characters, and
     * distinct from `credit_refund` (`AdjustCredits`), whose ids are hashes.
     */
    public const CREDIT_SOURCE_TYPE = 'order_reversal';

    public function __construct(
        private readonly ReversePayment $reverse,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Whether this order is one this Action reverses.
     */
    public static function reverses(Order $order): bool
    {
        if ($order->kind === OrderKind::Credits) {
            return true;
        }

        if ($order->kind !== OrderKind::Subscription) {
            return false;
        }

        return SubscriptionIntent::fromOrder($order)?->isSessionShaped() === true;
    }

    /**
     * How many credits reversing this order would take back — what the
     * officer is shown before confirming. The same lookup `handle()` claims.
     */
    public function refundableFor(Order $order): int
    {
        $lot = $this->lotFor($order);

        return $lot === null ? 0 : max(0, (int) $lot->credits_remaining);
    }

    public function handle(Order $order, User $by, string $reason): Order
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('اكتبْ سببَ العكس.');
        }

        if (! self::reverses($order)) {
            throw new DomainException('العكسُ من هنا لشراءِ الأرصدةِ وباقاتِ الحصصِ وحدَها.');
        }

        DB::transaction(function () use ($order, $by, $reason): void {
            // Without the workspace scope: a platform officer's context falls
            // back to their own workspace (the 024 defect's third layer).
            $claimed = Order::query()
                ->withoutWorkspaceScope()
                ->whereKey($order->getKey())
                ->where('status', OrderStatus::Approved->value)
                ->update(['status' => OrderStatus::Cancelled->value]);

            if ($claimed === 0) {
                throw new DomainException('هذا الطلبُ غيرُ معتمَدٍ الآن — عُكِسَ سلفاً أو لم يُعتمَد.');
            }

            $captured = PaymentTransaction::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->where('status', PaymentStatus::Captured->value)
                ->first();

            if ($captured === null) {
                // Rolls the whole transaction back, the claim above included.
                throw new DomainException('لا دفعةَ محصَّلةً على هذا الطلبِ لتُعكَس.');
            }

            $clawedBack = $this->clawBack($order, $by, $reason);

            $this->reverse->handle($captured, $reason);

            $this->logActivity('order.reversed', $order, [
                'reason' => $reason,
                'reversed_by' => $by->getKey(),
                'transaction_id' => $captured->getKey(),
                'credits_clawed_back' => $clawedBack,
            ]);
        });

        return $order->refresh();
    }

    /**
     * Take the purchase's unspent credits back out of its own lot.
     *
     * @return int how many credits were taken back
     */
    private function clawBack(Order $order, User $by, string $reason): int
    {
        $lot = $this->lotFor($order);

        if ($lot === null) {
            return 0;
        }

        $remainder = (int) $lot->credits_remaining;

        if ($remainder <= 0) {
            return 0;
        }

        // The claim, in the shape `ExpireCreditLotsJob` claims a lot: one
        // conditional UPDATE that both checks and takes. A session charged since
        // the officer read the number moved it, and the whole reversal rolls
        // back rather than taking back a credit that was just spent.
        $claimed = CreditLot::query()
            ->withoutWorkspaceScope()
            ->whereKey($lot->getKey())
            ->where('credits_remaining', $remainder)
            ->update(['credits_remaining' => 0]);

        if ($claimed === 0) {
            throw new DomainException('تغيّر رصيدُ هذا الشراءِ أثناءَ العكس — أعِد المحاولة.');
        }

        $balance = CreditBalance::query()->withoutWorkspaceScope()->findOrFail($lot->credit_balance_id);

        $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Refund,
            credits: -$remainder,
            sourceType: self::CREDIT_SOURCE_TYPE,
            // The idempotency key: one clawback per order, whatever replays.
            sourceId: (int) $order->getKey(),
            performedBy: (int) $by->getKey(),
            reason: $reason,
            // Money going out, as `AdjustCredits` enforces for a refund: never
            // below zero, so a stale number cannot take back what is not there.
            enforceFloor: true,
            // The lot above was emptied by the claim; letting the drawer run
            // would take the same credits again from other purchases' lots.
            drawsFromLots: false,
        ));

        return $remainder;
    }

    /**
     * The lot this order's purchase opened, found by the key each sale minted
     * under: a package on its `credit_purchases` row, an hours plan on the order.
     */
    private function lotFor(Order $order): ?CreditLot
    {
        $purchase = CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $order->getKey())
            ->first();

        if ($purchase === null) {
            return null;
        }

        [$sourceType, $sourceId] = $order->kind === OrderKind::Credits
            ? ['credit_purchase', (int) $purchase->getKey()]
            : [ActivateSubscription::CREDIT_SOURCE_TYPE, (int) $order->getKey()];

        $entry = CreditTransaction::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $purchase->credit_balance_id)
            ->where('type', CreditTransactionType::Purchase->value)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($entry === null) {
            return null;
        }

        return CreditLot::query()
            ->withoutWorkspaceScope()
            ->where('credit_transaction_id', $entry->getKey())
            ->first();
    }
}
