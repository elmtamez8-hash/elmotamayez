<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Data\ReconciliationWindow;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentReconciliationRun;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\CallbackPayloadSanitizer;
use App\Modules\Payments\Support\CallbackRecorder;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The payment that succeeded and never told us.
 *
 * ⚠️ IT ASKS `transactionsInWindow()`, NEVER `verify()` — and the difference is
 * US2 entire. `verify()` answers about a payment we already know exists; a
 * notification that was never delivered leaves us with nothing to ask about. The
 * only way to find money we do not know arrived is to ask the provider what it
 * settled in a stretch of TIME.
 *
 * ⚠️ AND IT REPLAYS THE CALLBACK RATHER THAN CAPTURING BY HAND. A second path
 * that moved a transaction to captured would be a second answer to who gets
 * enrolled, which order gets locked, and what happens to a payment on an order
 * that was cancelled. The sweep records the notification the provider should have
 * sent and hands it to {@see HandleProviderCallback}, so a reconciled payment and
 * a delivered one are the same event with different postmarks — and the unique
 * index on `(provider, external_id)` makes the two collapse into one when both
 * arrive.
 *
 * ⚠️ THE DIRECTION IS NOT SYMMETRIC (FR-016). `pending → captured` is applied
 * automatically: nothing was granted on the strength of a payment we had not
 * seen, so applying it can only give someone what they paid for. `captured →
 * failed` is NEVER applied: credits are minted, a student may already have sat
 * the session, and the ledger is append-only. It is recorded as unresolved and
 * left to a person. `PaymentStatus`'s transition table would refuse it anyway —
 * the finding exists so that the refusal is VISIBLE instead of silent.
 *
 * ⚠️ AND CASE-BY-CASE COMPARISON IS BLIND TO THE FAILURE THAT WILL ACTUALLY
 * HAPPEN. The charge succeeded, `status = captured`, and the queued listener
 * died: the provider says settled and so do we — perfect agreement, zero findings
 * every night for ever, and a student who paid is still blocked. The invariant
 * that SEES it comes from outside both parties, which is why the third check is
 * here: every captured credit order must carry a ledger entry.
 */
class ReconcilePayments extends Action
{
    /**
     * How many findings are stored in full.
     *
     * `unresolved_count` is always the true total, and a truncated run says so in
     * the log: a cap that reports itself as "everything" is how a broken deploy
     * reads as three problems instead of nine thousand.
     */
    private const SAMPLE_LIMIT = 200;

    /** Rows per pass of the invariant walk. */
    private const CHUNK = 500;

    public function __construct(
        private readonly CallbackRecorder $recorder,
        private readonly HandleProviderCallback $callbacks,
    ) {}

    /**
     * @param  iterable<PaymentProviderInterface>  $providers  ALREADY RESOLVED, and
     *                                                         never looked up by name in here. `ProviderExtensibilityTest` fails the
     *                                                         build over an Action that so much as mentions the registry, and it is
     *                                                         right to: resolving a provider by string is the dependency the interface
     *                                                         exists to remove, spelled as a string instead of as a class. Every other
     *                                                         Action receives its provider from the transaction it belongs to; this one
     *                                                         has no transaction to start from — the whole point is asking providers
     *                                                         about payments we do not know exist — so the JOB does the enumerating and
     *                                                         hands the answers over.
     */
    public function handle(iterable $providers, ?CarbonImmutable $now = null): PaymentReconciliationRun
    {
        $now ??= CarbonImmutable::now();

        // Ordered by `window_to`, not by `ran_at`: the next window starts where
        // the last one STOPPED, and a run that was retried later covers an older
        // stretch than one that ran before it.
        $previous = PaymentReconciliationRun::query()->orderByDesc('window_to')->first();

        $window = ReconciliationWindow::following($previous, $now);

        $findings = [];
        $checked = 0;
        $corrected = 0;

        foreach ($providers as $provider) {
            [$providerChecked, $providerCorrected, $providerFindings] =
                $this->reconcileProvider($provider, $window);

            $checked += $providerChecked;
            $corrected += $providerCorrected;
            $findings = [...$findings, ...$providerFindings];
        }

        // AFTER the provider pass, never before: a payment the provider reported
        // in this very window must be settled by its own outcome rather than
        // expired out from under it.
        $corrected += $this->expireStalePayments($now);

        $findings = [...$findings, ...$this->creditOrdersWithoutEntries()];

        // FR-017 — a notification that exhausted its retries is written down and
        // then read by nobody. Counted here so it reaches the one screen that
        // exists to show what is unresolved; scoped to the window by WHEN IT WAS
        // GIVEN UP ON, because that is the moment it became a problem, and an
        // all-time count would grow for ever and never fall.
        $abandoned = DB::table('provider_callbacks')
            ->where('result', CallbackResult::Abandoned->value)
            ->where('processed_at', '>=', $window->from)
            ->where('processed_at', '<', $window->to)
            ->count();

        $unresolved = count($findings) + $abandoned;

        if (count($findings) > self::SAMPLE_LIMIT) {
            Log::warning('payment reconciliation truncated its stored sample', [
                'found' => count($findings),
                'stored' => self::SAMPLE_LIMIT,
            ]);
        }

        return PaymentReconciliationRun::query()->create([
            'ran_at' => $now,
            'window_from' => $window->from,
            'window_to' => $window->to,
            'checked_count' => $checked,
            'corrected_count' => $corrected,
            'unresolved_count' => $unresolved,
            'findings' => array_slice($findings, 0, self::SAMPLE_LIMIT),
        ]);
    }

    /**
     * One provider's window, matched against our own records.
     *
     * ⚠️ THE MATCH IS A DICTIONARY, NEVER A QUERY PER EVENT. A lookup for each
     * transaction the provider reports is N queries a night that grows with the
     * business — the shape `ReconcileCreditBalancesJob` was written to avoid. The
     * references are collected first and read in one statement.
     *
     * @return array{0: int, 1: int, 2: list<array<string, mixed>>}
     */
    private function reconcileProvider(PaymentProviderInterface $provider, ReconciliationWindow $window): array
    {
        $identifier = $provider->identifier();

        /** @var list<CallbackEvent> $events */
        $events = [];

        foreach ($provider->transactionsInWindow($window->from, $window->to) as $event) {
            $events[] = $event;
        }

        if ($events === []) {
            return [0, 0, []];
        }

        $locals = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('provider', $identifier)
            ->whereIn('reference', array_map(fn (CallbackEvent $e): string => $e->reference, $events))
            ->get()
            ->keyBy('reference');

        $corrected = 0;
        $findings = [];

        foreach ($events as $event) {
            $local = $locals->get($event->reference);

            if ($local === null) {
                // The provider settled something we have no record of at all.
                // Never invented into existence here: an order, a workspace and
                // an amount would all have to be guessed, and a guess in this
                // table is money attributed to the wrong person.
                $findings[] = [
                    'type' => 'unknown_reference',
                    'provider' => $identifier,
                    'reference' => $event->reference,
                    'provider_status' => $event->status->value,
                ];

                continue;
            }

            if ($local->status->isFinal()) {
                if ($event->status !== $local->status) {
                    // The half that needs a human (FR-016). Recorded rather than
                    // applied, because everything downstream of a capture has
                    // already happened.
                    $findings[] = [
                        'type' => 'provider_disagrees',
                        'provider' => $identifier,
                        'reference' => $event->reference,
                        'local_status' => $local->status->value,
                        'provider_status' => $event->status->value,
                    ];
                }

                continue;
            }

            if ($this->replay($identifier, $event) === CallbackResult::Accepted) {
                $corrected++;
            }
        }

        return [count($events), $corrected, $findings];
    }

    /**
     * Deliver the notification the provider should have sent.
     *
     * Idempotent twice over: the recorder's unique index collapses a sweep and a
     * late webhook carrying the same event id into one row, and the capture
     * itself is an atomic conditional UPDATE that the second caller loses. Two
     * overlapping runs therefore correct the same payment once — SC-005 — without
     * this method knowing anything about the other one.
     */
    private function replay(string $identifier, CallbackEvent $event): ?CallbackResult
    {
        $callback = $this->recorder->record(
            $identifier,
            $event->externalId,
            true,
            CallbackPayloadSanitizer::scrub($event->safePayload),
            null,
        );

        if (! $this->recorder->isFresh($callback)) {
            // Already recorded — either handled, or in flight on the payments
            // queue. Either way this pass has nothing to add.
            return null;
        }

        return $this->callbacks->handle($callback, $event);
    }

    /**
     * Payments that never came back, closed with a final status (FR-015).
     *
     * ⚠️ A PENDING ROW IS NOT A NEUTRAL ONE. It holds an order open, it is what
     * an operator sees when they ask whether a student paid, and it is the state
     * a payer's abandoned browser tab leaves behind — the commonest outcome of a
     * redirect flow, not an edge case. Left for ever, the collection report
     * becomes a list of maybes.
     *
     * ⚠️ ONE CONDITIONAL UPDATE PER ROW, `WHERE status = pending`. A bulk update
     * over the whole set would be cheaper and would also expire a payment whose
     * callback landed in the same instant — the claim has to be the check.
     *
     * Counted as CORRECTED, not as a finding: the sweep resolved it. What an
     * operator needs is on the transaction itself, in `failure_reason`.
     */
    private function expireStalePayments(CarbonImmutable $now): int
    {
        $cutoff = $now->subMinutes((int) config('payments.pending_timeout_minutes', 120));
        $expired = 0;

        PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (iterable $transactions) use (&$expired, $now): void {
                foreach ($transactions as $transaction) {
                    // Asked, not assumed, even though the query above selected
                    // the only two statuses it can be true for. The enum is the
                    // one copy of the transition table, and a status compared to
                    // a constant here would be the second.
                    if (! $transaction->status->canTransitionTo(PaymentStatus::Expired)) {
                        continue;
                    }

                    $claimed = PaymentTransaction::query()
                        ->withoutWorkspaceScope()
                        ->whereKey($transaction->getKey())
                        ->where('status', $transaction->status->value)
                        ->update([
                            'status' => PaymentStatus::Expired->value,
                            // FR-008 — a reason the payer can read. "Expired"
                            // alone is a status; this is a sentence.
                            'failure_reason' => 'انتهت مهلة إتمام الدفع دون تأكيد من مزوّد الدفع.',
                            'settled_at' => $now,
                        ]);

                    if ($claimed > 0) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    /**
     * Every captured credit order must carry a ledger entry (the third check).
     *
     * ⚠️ THIS IS THE ONE THAT SEES THE FAILURE THE OTHER TWO CANNOT. Comparing
     * our status against the provider's is a comparison between two parties who
     * agree: the payment settled, we wrote `captured`, and the queued mint died
     * in between. Both sides say "paid" and the student has nothing.
     *
     * Grouped reads and a `chunkById` walk, never a query per order: this is a
     * join over the two tables that grow with every sale, running nightly.
     *
     * @return list<array<string, mixed>>
     */
    private function creditOrdersWithoutEntries(): array
    {
        $findings = [];

        DB::table('payment_transactions as t')
            ->join('orders as o', 'o.id', '=', 't.captured_order_id')
            ->where('t.status', PaymentStatus::Captured->value)
            ->where('o.kind', OrderKind::Credits->value)
            ->select(['o.id as order_id', 'o.uuid as order_uuid'])
            ->orderBy('o.id')
            ->chunkById(self::CHUNK, function (iterable $orders) use (&$findings): void {
                $uuids = [];

                foreach ($orders as $order) {
                    $uuids[(int) $order->order_id] = (string) $order->order_uuid;
                }

                // Read 1: the purchase behind each order.
                $purchases = DB::table('credit_purchases')
                    ->whereIn('order_id', array_keys($uuids))
                    ->pluck('order_id', 'id');

                // Read 2: which of those purchases produced a ledger entry.
                $minted = DB::table('credit_transactions')
                    ->where('source_type', 'credit_purchase')
                    ->whereIn('source_id', $purchases->keys()->all())
                    ->pluck('source_id')
                    ->all();

                $mintedOrders = [];

                foreach ($purchases as $purchaseId => $orderId) {
                    if (in_array($purchaseId, $minted, true)) {
                        $mintedOrders[(int) $orderId] = true;
                    }
                }

                foreach ($uuids as $orderId => $orderUuid) {
                    if (! isset($mintedOrders[$orderId])) {
                        $findings[] = [
                            'type' => 'captured_without_credits',
                            'order_uuid' => $orderUuid,
                        ];
                    }
                }
            }, 'o.id', 'order_id');

        return $findings;
    }
}
