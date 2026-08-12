<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderCallback;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

/**
 * Applies one verified callback to the transaction it names.
 *
 * This is the sharpest code in the phase; four rules hold it up.
 *
 * ⚠️ 1. THE SIGNATURE IS NOT AN AMOUNT CHECK. It proves who sent the message,
 * not that the message agrees with our record. A provider misconfigured onto the
 * wrong merchant account, or a replay of a real notification against a different
 * order, both arrive perfectly signed. So the amount AND the currency are
 * compared, and a difference is `mismatch` — recorded, not applied.
 *
 * ⚠️ 2. THE TRANSACTION IS FOUND BY (provider, reference) TOGETHER. The unique
 * index is on the pair, and the pair is what stops a callback signed with one
 * provider's secret from claiming another provider's transaction. Academic while
 * there is one provider — and the registry exists so that there will not be.
 *
 * ⚠️ 3. THE MOVE TO CAPTURED IS ONE ATOMIC CONDITIONAL UPDATE, and it writes
 * `captured_order_id` IN THE SAME STATEMENT. A second call would leave a window
 * between the status and the lock. The loser of that update SKIPS THE WHOLE
 * EFFECT — one event, one mint — which is what makes a webhook racing the
 * reconciliation sweep safe under concurrency rather than merely in sequence.
 *
 * ⚠️ 4. A PAYMENT THAT SUCCEEDS ON AN ORDER THAT NO LONGER STANDS IS NEVER
 * REFUSED. The money left the student's account; refusing the transaction would
 * be a debit with nothing behind it. It is captured and turned into credits with
 * a recorded reason, and it closes no order.
 */
class HandleProviderCallback extends Action
{
    use LogsActivity;

    public function __construct(private readonly AdjustCredits $adjustCredits) {}

    public function handle(ProviderCallback $callback, CallbackEvent $event): CallbackResult
    {
        $transaction = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('provider', $event->provider)
            ->where('reference', $event->reference)
            ->first();

        if ($transaction === null) {
            // Not a failure: the provider may legitimately answer faster than
            // our own write commits. The job retries under its declared limit.
            return $this->finish($callback, CallbackResult::Deferred, null);
        }

        if ($transaction->status->isFinal()) {
            return $this->finish($callback, CallbackResult::Duplicate, $transaction);
        }

        if ($event->amountMinor !== $transaction->amount_minor || $event->currency !== $transaction->currency) {
            $this->transitionTo($transaction, PaymentStatus::Mismatch, $event->failureReason);

            return $this->finish($callback, CallbackResult::Mismatch, $transaction);
        }

        if ($event->status !== PaymentStatus::Captured) {
            $applied = $this->transitionTo($transaction, $event->status, $event->failureReason);

            if ($applied) {
                event(new PaymentFailed($this->orderOf($transaction), $transaction, $event->failureReason));
            }

            return $this->finish($callback, CallbackResult::Accepted, $transaction);
        }

        return $this->capture($callback, $transaction);
    }

    private function capture(ProviderCallback $callback, PaymentTransaction $transaction): CallbackResult
    {
        $order = $this->orderOf($transaction);

        /*
        | ⚠️ ONE STATEMENT. `WHERE status = 'pending'` is the check and the claim
        | at once, and `captured_order_id` is set by the same UPDATE — the seat
        | idiom from 005, and never `lockForUpdate()`, which is a no-op on SQLite
        | and so proves nothing locally about the MySQL it will run on.
        |
        | An order that no longer stands takes NULL for `captured_order_id`: the
        | capture is real, but there is no order for it to lock.
        */
        $stillOpen = $order->status !== 'cancelled' && $order->status !== 'rejected';

        $claimed = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('id', $transaction->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->update([
                'status' => PaymentStatus::Captured->value,
                'captured_order_id' => $stillOpen ? $order->getKey() : null,
                'settled_at' => now(),
            ]);

        if ($claimed === 0) {
            // Someone else — the reconciliation sweep, a duplicate resend — got
            // there first. Skipping the effect is the point: one event, one mint.
            return $this->finish($callback, CallbackResult::Duplicate, $transaction);
        }

        $transaction->refresh();

        if (! $stillOpen) {
            $this->creditOrphanedPayment($transaction, $order);

            return $this->finish($callback, CallbackResult::Accepted, $transaction);
        }

        // After the write, not inside it: a listener that enrols a student on a
        // payment whose write then rolls back has enrolled them for nothing.
        event(new PaymentCaptured($order, $transaction));

        return $this->finish($callback, CallbackResult::Accepted, $transaction);
    }

    /**
     * The money arrived for an order that had been cancelled or rejected.
     *
     * ⚠️ The spec answers this explicitly: it is credited and MUST NOT be
     * refused after the debit succeeded. Refusing it would leave the student
     * charged with nothing to show. It becomes credits with a recorded reason,
     * and it closes no order — the order is gone.
     */
    private function creditOrphanedPayment(PaymentTransaction $transaction, Order $order): void
    {
        $balance = CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $order->user_id)
            ->where('course_id', $order->course_id)
            ->first();

        if ($balance === null) {
            // Nothing to credit it onto — recorded and left for the operator
            // rather than silently dropped.
            $this->logActivity('payment.captured_orphan_unresolved', $transaction, [
                'order_id' => $order->getKey(),
            ]);

            return;
        }

        $this->adjustCredits->handle(
            balance: $balance,
            type: CreditTransactionType::Refund,
            credits: 1,
            reason: 'دفعة نجحت بعد إلغاء الطلب — قُيِّدت رصيداً ولم تُرفض.',
            idempotencyKey: 'orphan-capture-'.$transaction->uuid,
        );
    }

    /**
     * The order this payment belongs to.
     *
     * `payment_transactions.order_id` is NOT NULL, so a miss here is a corrupt
     * row rather than a case to branch on — and `withoutWorkspaceScope()` is
     * stated because this runs on a webhook, where there is no tenant in context
     * and the scope adds no condition anyway. Saying it makes that a decision
     * rather than a coincidence.
     */
    private function orderOf(PaymentTransaction $transaction): Order
    {
        return Order::query()
            ->withoutWorkspaceScope()
            ->findOrFail($transaction->order_id);
    }

    /**
     * A conditional move that respects the enum's transition table.
     *
     * Returns false when someone else moved the row first — the caller decides
     * whether that is a duplicate or a race it should stay out of.
     */
    private function transitionTo(PaymentTransaction $transaction, PaymentStatus $next, ?string $reason): bool
    {
        if (! $transaction->status->canTransitionTo($next)) {
            return false;
        }

        return PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('id', $transaction->getKey())
            ->where('status', $transaction->status->value)
            ->update([
                'status' => $next->value,
                'failure_reason' => $reason,
                'settled_at' => now(),
            ]) > 0;
    }

    private function finish(ProviderCallback $callback, CallbackResult $result, ?PaymentTransaction $transaction): CallbackResult
    {
        DB::transaction(function () use ($callback, $result, $transaction): void {
            $callback->forceFill([
                'result' => $result,
                'payment_transaction_id' => $transaction?->getKey(),
                // Resolved from the ORDER, never read from the payload — a body
                // the other side wrote is not an authorisation source.
                'workspace_id' => $transaction?->workspace_id,
                // Deferred rows stay unprocessed on purpose: `processed_at` is
                // what the retry queue reads.
                'processed_at' => $result === CallbackResult::Deferred ? null : now(),
            ])->save();
        });

        return $result;
    }
}
