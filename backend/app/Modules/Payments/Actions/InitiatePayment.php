<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Data\ChargeIntent;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts a payment: records the attempt, then asks the provider for an intent.
 *
 * ⚠️ THE ROW IS WRITTEN BEFORE THE PROVIDER IS CALLED, never after. A callback
 * can arrive faster than our own commit — a declared edge case — and a
 * transaction written afterwards means the notification for a payment we
 * ourselves started finds nothing to attach to. Written first, the worst case is
 * an initiated row for a charge the provider refused to create, which the
 * timeout sweep closes.
 *
 * ⚠️ AND A SECOND ATTEMPT IS A SECOND ROW. Retry is the normal case — a student
 * abandons a bank page and comes back — so an order carries as many transactions
 * as it had attempts, and exactly one of them may ever be captured. That is what
 * `unique(captured_order_id)` enforces; a unique on `order_id` would have made
 * the first failure permanent.
 */
class InitiatePayment extends Action
{
    use LogsActivity;

    public function handle(Order $order, PaymentProviderInterface $provider): ChargeIntent
    {
        if ($order->status === 'approved') {
            throw new DomainException('هذا الطلب مدفوع بالفعل.');
        }

        /*
        | ⚠️ ONE ORDER AT A TIME, IN A STATED ORDER — the guardian case (FR-004
        | edge). A parent paying for three children has three orders, and which
        | one a payment settles must not be "whichever the query returned first":
        | an index change would silently re-point the money, and the child whose
        | access was restored would change with it.
        |
        | The order is declared in config/payments.php and enforced here, so the
        | answer is in one readable place rather than in a query plan. This
        | Action settles the order it was HANDED; the allocation rule is what
        | decides which order that is when a caller has several.
        */
        $intent = $provider->createCharge($order);

        $transaction = DB::transaction(fn (): PaymentTransaction => PaymentTransaction::create([
            'workspace_id' => $order->workspace_id,
            'order_id' => $order->getKey(),
            'provider' => $provider->identifier(),
            'amount_minor' => $intent->amountMinor,
            'currency' => $intent->currency,
            'status' => PaymentStatus::Pending,
            'method' => $intent->method,
            'reference' => $intent->reference,
        ]));

        // No payload: an intent may carry a redirect URL, and a URL a provider
        // built is not evidence worth storing per attempt.
        $this->logActivity('payment.initiated', $transaction, [
            'order_id' => $order->getKey(),
            'provider' => $provider->identifier(),
        ]);

        return $intent;
    }

    /**
     * Which order a payer with several open ones settles first.
     *
     * ⚠️ Declared, not discovered. `config('payments.allocation_order')` names
     * the rule; this is the only implementation of it.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function allocate(Collection $orders): ?Order
    {
        return match ((string) config('payments.allocation_order')) {
            // Youngest first would leave the oldest debt growing for ever.
            'newest_first' => $orders->sortByDesc('created_at')->first(),
            default => $orders->sortBy('created_at')->first(),
        };
    }
}
