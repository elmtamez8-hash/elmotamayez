<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\BalanceUpdated;
use App\Modules\Payments\Events\CreditsPurchased;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONE place credits are added for consideration.
 *
 * Everything else that raises a balance — a bonus, a correction — is an
 * adjustment with a recorded reason and a platform permission behind it. Keeping
 * the paid path to a single Action is what makes "credits with money behind
 * them" a checkable claim rather than a convention.
 *
 * Idempotent by construction: the entry is keyed on the purchase, so a
 * redelivered PaymentApproved writes nothing the second time and reports the
 * same success.
 */
class RecordCreditPurchase extends Action
{
    public function __construct(private readonly CreditLedger $ledger) {}

    public function handle(Order $order): ?CreditTransaction
    {
        if ($order->kind !== OrderKind::Credits) {
            return null;
        }

        $purchase = CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $order->getKey())
            ->first();

        if ($purchase === null) {
            // An approved credit order with no purchase behind it is a broken
            // write, not an empty case: the price snapshot is what spec 015's
            // books are generated from, and minting credits without one would
            // put an unpriced entry in the ledger for ever.
            throw new RuntimeException("Approved credit order {$order->getKey()} has no credit_purchase row.");
        }

        $balance = $purchase->balance()->withoutWorkspaceScope()->firstOrFail();

        $entry = $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Purchase,
            credits: $purchase->credits,
            sourceType: 'credit_purchase',
            sourceId: (int) $purchase->getKey(),
            performedBy: $order->approved_by === null ? null : (int) $order->approved_by,
            expiresAt: $this->expiryFor($purchase),
        ));

        if ($entry === null) {
            return null;
        }

        // After commit, never inside. An event fired inside the transaction
        // announces a movement that may still roll back — and the student is
        // told they have credits they do not have.
        DB::afterCommit(function () use ($entry, $balance, $purchase): void {
            CreditsPurchased::dispatch($entry, $purchase);
            BalanceUpdated::dispatch($balance->refresh(), $entry->credits);
        });

        return $entry;
    }

    /**
     * When this batch dies, or null for never — which is the launch policy (Q-5).
     *
     * Read from the package at purchase time and frozen onto the lot, not read
     * through the package later: FR-019 says changing a package must not touch
     * credits already bought from it, and a validity read live would do exactly
     * that.
     */
    private function expiryFor(CreditPurchase $purchase): ?DateTimeInterface
    {
        // No scope bypass: a package is platform reference data with no
        // workspace_id at all (constitution v1.2.0 §I, kind ب).
        $days = $purchase->package()->first()?->validity_days;

        return $days === null ? null : CarbonImmutable::now()->addDays($days);
    }
}
