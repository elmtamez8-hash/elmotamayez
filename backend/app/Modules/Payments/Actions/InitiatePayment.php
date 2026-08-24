<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Data\ChargeIntent;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\PaymentReturnUrl;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a payment: records the attempt, then asks the provider for an intent.
 *
 * ⚠️ THIS DOCBLOCK USED TO CLAIM «THE ROW IS WRITTEN BEFORE THE PROVIDER IS
 * CALLED, NEVER AFTER», AND THE CODE BELOW HAS ALWAYS DONE THE OPPOSITE. The
 * claim is corrected rather than the order, because on inspection the invariant
 * it states cannot be reached from here: a callback is matched on the PROVIDER'S
 * reference (`unique(provider, reference)`), and that reference does not exist
 * until `createCharge()` returns. Writing our row first would produce a row the
 * arriving callback still cannot find — the race unchanged, and a false sentence
 * standing over it. The residual question of a gateway that calls back faster
 * than we commit belongs to spec 007 and is filed there, not guessed at here.
 *
 * What IS ordered on purpose: the transaction's uuid is minted BEFORE the
 * provider call, because the return URL carries it and the gateway needs that
 * URL at the moment it is asked for a charge. `HasUuid` only generates when the
 * attribute is null, so the value handed out and the value persisted are the
 * same one — which is what the contract test measures.
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

    public function __construct(private readonly PaymentReturnUrl $returnUrls) {}

    public function handle(Order $order, PaymentProviderInterface $provider): ChargeIntent
    {
        if ($order->status === 'approved') {
            throw new DomainException('هذا الطلب مدفوع بالفعل.');
        }

        /*
        | ⚠️ MINTED HERE, NOT LEFT TO THE MODEL. The gateway is told where to send
        | the payer back to at the moment it is asked for a charge, and that URL
        | names this attempt — so the uuid has to exist one line before the
        | provider call rather than one line after it.
        |
        | `uuid` is deliberately not `$fillable`, so it is set on the instance:
        | mass-assignable, it would be a second way to choose a row's identity
        | from outside.
        */
        $uuid = (string) Str::orderedUuid();

        $intent = $provider->createCharge($order, $this->returnUrls->for($uuid));

        $transaction = DB::transaction(function () use ($order, $provider, $intent, $uuid): PaymentTransaction {
            $transaction = new PaymentTransaction([
                'workspace_id' => $order->workspace_id,
                'order_id' => $order->getKey(),
                'provider' => $provider->identifier(),
                'amount_minor' => $intent->amountMinor,
                'currency' => $intent->currency,
                'status' => PaymentStatus::Pending,
                'method' => $intent->method,
                'reference' => $intent->reference,
            ]);

            $transaction->uuid = $uuid;
            $transaction->save();

            return $transaction;
        });

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
     * ⚠️ ONE ORDER AT A TIME, IN A STATED ORDER — the guardian case (FR-004
     * edge). A parent paying for three children has three orders, and which one
     * a payment settles must not be "whichever the query returned first": an
     * index change would silently re-point the money, and the child whose access
     * was restored would change with it.
     *
     * ⚠️ Declared, not discovered. `config('payments.allocation_order')` names
     * the rule; this is the only implementation of it — and `handle()` above
     * settles the order it was HANDED, so this is what decides which order that
     * is when a caller has several.
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
