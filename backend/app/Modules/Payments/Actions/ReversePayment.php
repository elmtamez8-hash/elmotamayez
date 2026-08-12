<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentReversed;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * A captured payment taken back — a chargeback, a bank reversal.
 *
 * ⚠️ A NEW ENTRY, NOT AN EDIT. The capture stays exactly as it was recorded; the
 * status moves forward to `reversed` and the reason is written beside it.
 * Rewriting the capture would destroy the record of a payment that really did
 * happen, in the module whose FR-027 forbids editing its ledger.
 *
 * ⚠️ AND `captured_order_id` IS CLEARED. Without that one line, a student whose
 * payment was reversed by a bank dispute can NEVER pay for that order again —
 * the unique index still holds their old capture's claim — and the failure is
 * silent and permanent. It is the mirror of the write in HandleProviderCallback,
 * and the two belong in the same reading.
 */
class ReversePayment extends Action
{
    use LogsActivity;

    public function handle(PaymentTransaction $transaction, string $reason): PaymentTransaction
    {
        if ($transaction->status !== PaymentStatus::Captured) {
            throw new DomainException('لا يمكن عكس عملية لم تُحصَّل.');
        }

        $applied = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('id', $transaction->getKey())
            ->where('status', PaymentStatus::Captured->value)
            ->update([
                'status' => PaymentStatus::Reversed->value,
                'failure_reason' => $reason,
                // ⚠️ The line that lets the student pay again.
                'captured_order_id' => null,
            ]);

        if ($applied === 0) {
            throw new DomainException('تغيّرت حالة العملية أثناء العكس.');
        }

        $transaction->refresh();

        $this->logActivity('payment.reversed', $transaction, [
            'order_id' => $transaction->order_id,
            'reason' => $reason,
        ]);

        $order = Order::query()->withoutWorkspaceScope()->findOrFail($transaction->order_id);

        event(new PaymentReversed($order, $transaction, $reason));

        return $transaction;
    }
}
