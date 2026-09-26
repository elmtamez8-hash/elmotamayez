<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * «تمّ الردّ» — the officer records that the money owed back has been sent.
 *
 * ⛔ `refund_due` HAD NO WAY OUT. The store writes it twice — the buyer's own
 * refund inside the window, and a paid order whose last copy went before the
 * approval — and nothing ever read it: the panel could not filter by it, no
 * button moved it, and the capture stayed `captured`, so the collection report
 * went on counting money the platform had promised to give back.
 *
 * Two writes in one transaction, in this order, for the reasons
 * {@see ReverseCourseOrder} gives for the same two:
 *
 *   1) **the order is claimed first**, `refund_due → cancelled` by a
 *      conditional UPDATE. Two presses both read `refund_due`; the loser hits
 *      zero rows and reads a sentence instead of reversing a reversed payment.
 *      `cancelled` because it is what every other reversal lands on, and
 *      `HandleProviderCallback::closesOrder()` already refuses it — a late
 *      gateway notice cannot reopen it.
 *   2) **then `ReversePayment`**, which moves the capture to `reversed` — the
 *      one change `BuildCollectionReport` reads — and fires `PaymentReversed`,
 *      whose listener tells the buyer. No notification is sent from here.
 *
 * ⚠️ A `refund_due` ORDER WITH NO CAPTURE IS REFUSED, NOT CLOSED QUIETLY. Before
 * `RefundStorePurchase` learned to refuse unpaid orders, an order refunded
 * while still `pending` could reach this status with no money ever taken; the
 * officer sees that sentence and rejects nothing that was paid.
 *
 * ⚠️ NO STORE IMPORT. `refund_due` is a value of Payments' own column, and
 * `ContextIsolationTest` fails the build over a Payments class naming a Store
 * model — the store row's `refunded_at` was already stamped by its own Action.
 */
class SettleRefundDue extends Action
{
    use LogsActivity;

    public function __construct(private readonly ReversePayment $reverse) {}

    public function handle(Order $order, User $by, string $reason): Order
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('اكتبْ ما يُثبِتُ الردَّ — رقمَ التحويل أو وسيلتَه.');
        }

        DB::transaction(function () use ($order, $by, $reason): void {
            // ⚠️ Unscoped: the platform officer's context falls back to their own
            // `last_workspace_id`, and a scoped claim hits zero rows on every
            // other teacher's order and reads «settled already».
            $claimed = Order::query()
                ->withoutWorkspaceScope()
                ->whereKey($order->getKey())
                ->where('status', OrderStatus::RefundDue->value)
                ->update(['status' => OrderStatus::Cancelled->value]);

            if ($claimed === 0) {
                throw new DomainException('هذا الطلبُ ليسَ مستحقَّ الاسترداد الآن — سُجِّلَ ردُّه سلفاً.');
            }

            $captured = PaymentTransaction::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->where('status', PaymentStatus::Captured->value)
                ->first();

            if ($captured === null) {
                // Rolls the claim above back with it.
                throw new DomainException('لا دفعةَ محصَّلةً على هذا الطلب، فلا مبلغَ يُرَدّ.');
            }

            $this->reverse->handle($captured, $reason);

            $this->logActivity('order.refund_settled', $order, [
                'reason' => $reason,
                'settled_by' => $by->getKey(),
                'transaction_id' => $captured->getKey(),
            ]);
        });

        return $order->refresh();
    }
}
