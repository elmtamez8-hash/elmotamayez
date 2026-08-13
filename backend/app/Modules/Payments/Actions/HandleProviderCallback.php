<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Models\CreditPurchase;
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
 * ⚠️ 4. A PAYMENT WITH NO ORDER LEFT TO CLOSE IS NEVER REFUSED — whether the
 * order was cancelled first or the payer simply paid twice. The money left their
 * account; refusing the transaction would be a debit with nothing behind it. It
 * is captured and turned into credits with a recorded reason, and it claims no
 * order.
 */
class HandleProviderCallback extends Action
{
    use LogsActivity;

    public function __construct(private readonly RecordCreditPurchase $purchases) {}

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
        $closesOrder = $this->closesOrder($order);

        $claimed = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('id', $transaction->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->update([
                'status' => PaymentStatus::Captured->value,
                'captured_order_id' => $closesOrder ? $order->getKey() : null,
                'settled_at' => now(),
            ]);

        if ($claimed === 0) {
            // Someone else — the reconciliation sweep, a duplicate resend — got
            // there first. Skipping the effect is the point: one event, one mint.
            return $this->finish($callback, CallbackResult::Duplicate, $transaction);
        }

        $transaction->refresh();

        if (! $closesOrder) {
            $this->creditSurplusPayment($transaction, $order);

            return $this->finish($callback, CallbackResult::Accepted, $transaction);
        }

        // After the write, not inside it: a listener that enrols a student on a
        // payment whose write then rolls back has enrolled them for nothing.
        event(new PaymentCaptured($order, $transaction));

        return $this->finish($callback, CallbackResult::Accepted, $transaction);
    }

    /**
     * Whether this capture is the one that closes its order.
     *
     * Two ways it is not, and the second was a 500 waiting to happen:
     *
     *   · the order was cancelled or rejected before the money landed;
     *   · the order is ALREADY HELD by a captured transaction — the payer paid
     *     twice. `captured_order_id` is unique, so writing it a second time
     *     throws; asking first is what turns "طالب دفع مرتين" from a crashed
     *     webhook into the surplus the spec says it is.
     *
     * The narrow race — two callbacks for two payments claiming the order in the
     * same instant — still hits the index, and that is the correct end of it: the
     * queued job retries, the retry sees the captured row, and takes the surplus
     * path. Self-healing, because the invariant is in the engine rather than in
     * this method.
     */
    private function closesOrder(Order $order): bool
    {
        if ($order->status === 'cancelled' || $order->status === 'rejected') {
            return false;
        }

        return ! PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('captured_order_id', $order->getKey())
            ->exists();
    }

    /**
     * Money that arrived with no order left to close (FR-025أ).
     *
     * ⚠️ NEVER REFUSED AFTER THE DEBIT SUCCEEDED. The money left the payer's
     * account; refusing the transaction would be a charge with nothing behind it.
     * It becomes credits with a recorded reason and closes no order.
     *
     * The conversion and the policy behind it live in {@see RecordCreditPurchase},
     * which is where every credit added for consideration is written — a second
     * conversion here would be the copy that drifts from the snapshot.
     *
     * ⚠️ AND A COURSE ORDER IS RECORDED, NOT INVENTED. There is no price snapshot
     * to convert against, so there is no honest number of credits; the operator
     * gets a row naming the transaction instead of the student getting a figure
     * nobody derived.
     */
    private function creditSurplusPayment(PaymentTransaction $transaction, Order $order): void
    {
        $purchase = CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $order->getKey())
            ->first();

        if ($purchase === null) {
            $this->logActivity('payment.captured_surplus_unresolved', $transaction, [
                'order_id' => $order->getKey(),
                /*
                 * ⚠️ THE WORKSPACE IS PASSED, NOT LEFT TO THE TRAIT (FR-026).
                 * This Action runs inside a queued job and inside the
                 * reconciliation sweep, where `WorkspaceContext` resolves to
                 * null — and the trait's ambient stamp would therefore write
                 * `workspace_id: null` onto the one entry a person will later
                 * need to attribute. The order knows; nothing else in scope does.
                 * The trait merges properties second, so this wins.
                 */
                'workspace_id' => $order->workspace_id,
            ]);

            return;
        }

        $this->purchases->recordSurplus($transaction, $purchase);
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
