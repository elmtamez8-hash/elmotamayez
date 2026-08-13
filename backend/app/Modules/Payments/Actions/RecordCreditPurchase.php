<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\CreditsPurchased;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\BalanceAnnouncer;
use App\Modules\Payments\Support\BillingSettings;
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
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly BalanceAnnouncer $announcer,
        private readonly BillingSettings $settings,
        private readonly EvaluateCreditLimit $limits,
    ) {}

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

        // FR-033 — this is the movement that lifts a hold, and the reason the
        // lift needs no job and no operator: the predicate simply answers
        // differently on the next attempt, and this tells the student so.
        $wasBlocked = $this->announcer->isBlocked($balance);

        // ⚠️ ASKED BEFORE THE MOVEMENT, for the same reason `$wasBlocked` is.
        // Paying is what clears `negative_since`, so a purchase that settled a
        // twenty-day-old debt looks — one line later — exactly like a purchase by
        // a student who was never overdue at all. Every payer would be on time.
        $onTime = $this->wasOnTime($balance);

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

        $this->recordPunctuality($balance, $onTime);

        // After commit, never inside. An event fired inside the transaction
        // announces a movement that may still roll back — and the student is
        // told they have credits they do not have.
        DB::afterCommit(function () use ($entry, $balance, $purchase, $wasBlocked): void {
            CreditsPurchased::dispatch($entry, $purchase);

            $this->announcer->announce($balance, $wasBlocked, $entry->credits);

            // After the announcement, because `announce()` refreshes the instance
            // — and the streak this reads was written a moment ago by a query the
            // model in memory knows nothing about.
            $this->limits->handle($balance);
        });

        return $entry;
    }

    /**
     * Money that arrived with no order left to close — credited, never kept.
     *
     * FR-025أ, and the two ways it happens are the same shape: a payer who pays
     * twice for one order, and a payment that succeeds after the order was
     * cancelled. Both leave a captured transaction the platform holds and a payer
     * who is out the money.
     *
     * ⚠️ THE DECLARED POLICY, in one place: the money buys credits at the price
     * THIS purchase was snapshotted at, rounded UP to a whole credit. Up, because
     * Q-4 removed the cash balance a remainder could have lived in — rounding
     * down would leave a few riyals with nowhere to go, which is the "يُمنع أن
     * يضيع" the requirement is written against. The platform absorbs less than one
     * credit; the payer loses nothing. In practice it is exact: the amount is
     * provider-verified equal to the order, so it converts to whole credits.
     *
     * ⚠️ AND IT IS A `purchase`, NOT A `refund`. The shipped placeholder posted
     * `+1 refund`, which said the opposite of what happened in the student's own
     * statement, fired `RefundIssued` for money coming IN, and — because only
     * purchases and bonuses open a lot — raised `remaining` with no lot behind it.
     * That last one is not cosmetic: `sum(lots) = max(remaining, 0)` is an
     * invariant ReconcileCreditBalancesJob checks every night, and the balance
     * would have been reported broken for ever.
     */
    public function recordSurplus(PaymentTransaction $transaction, CreditPurchase $purchase): ?CreditTransaction
    {
        if ($purchase->total_minor <= 0 || $purchase->credits <= 0) {
            return null;
        }

        $balance = $purchase->balance()->withoutWorkspaceScope()->firstOrFail();

        $credits = (int) ceil($transaction->amount_minor * $purchase->credits / $purchase->total_minor);

        $wasBlocked = $this->announcer->isBlocked($balance);

        $entry = $this->ledger->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Purchase,
            credits: $credits,
            // Its own key, not the purchase's: keyed by the purchase it would
            // collide with the mint that closed the order, be read as a duplicate
            // and swallowed — the exact failure the refund key was moved away
            // from.
            sourceType: 'payment_surplus',
            sourceId: (int) $transaction->getKey(),
            reason: 'دفعة زائدة عن قيمة الطلب — قُيِّدت رصيداً ولم تُرفض.',
            expiresAt: $this->expiryFor($purchase),
        ));

        if ($entry === null) {
            return null;
        }

        DB::afterCommit(fn () => $this->announcer->announce($balance, $wasBlocked, $entry->credits));

        return $entry;
    }

    /**
     * Was the balance clear of a late debt when this payment landed? (FR-037)
     *
     * "Late" is the same fourteen days the demotion uses, read from the same key:
     * two definitions of lateness would let a student be punished by one and
     * rewarded by the other in the same week.
     */
    private function wasOnTime(CreditBalance $balance): bool
    {
        $since = $balance->negative_since;

        return $since === null || $since->gt(now()->subDays($this->settings->decreaseAfterLateDays()));
    }

    /**
     * Move the streak: one step forward, or all the way back.
     *
     * A relative increment rather than a read-and-write — two purchases approved
     * in the same second would otherwise both read 2 and both write 3.
     */
    private function recordPunctuality(CreditBalance $balance, bool $onTime): void
    {
        $row = DB::table('credit_balances')->where('id', $balance->getKey());

        $onTime
            ? $row->increment('on_time_payments')
            : $row->update(['on_time_payments' => 0]);
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
