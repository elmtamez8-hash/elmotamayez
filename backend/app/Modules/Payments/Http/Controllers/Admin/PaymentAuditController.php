<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Http\Resources\BillingAuditEntryResource;
use App\Modules\Payments\Models\CreditAllocation;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\BillingAuditSubjects;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Models\ActivityEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every administrative act on a student's money, and nothing else (FR-026 · FR-034).
 *
 * ⚠️ NO ACTION AND NO FormRequest, WHICH DEPARTS FROM THE CONSTITUTION'S CHAIN ON
 * PURPOSE — declared in `plan.md` §Complexity Tracking and repeated here, where
 * it is read. The precedent is `SettlementAuditController`, shipped in 014: this
 * reads one table with a filter that is a final class with no branch in it. An
 * Action would be a method that forwards its arguments, and a FormRequest would
 * validate a route model the router already resolved.
 *
 * ⚠️ THE FILTER IS THE QUERY, NOT A PASS OVER ITS RESULTS. `activity_log` is one
 * shared table that settlement writes to as well; asking for the table and then
 * dropping the other context's rows is one forgotten branch away from showing an
 * auditor of student payments what a teacher was paid.
 *
 * ⚠️ AND THIS LIST IS SCOPED BY NEITHER THE CALLER'S WORKSPACE NOR THEIR OWN
 * rows. `activity_log` has no `workspace_id` column and no global scope, and
 * `BILLING_AUDIT_VIEW` is a platform permission — platform-wide is the answer
 * FR-029 asks for, but it is a deliberate answer rather than an oversight, and a
 * second consumer must not assume a tenant filter is here.
 */
class PaymentAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // A bare permission check rather than a policy, because there is no
        // subject to build one around: the list spans seven models and asks one
        // question about the reader, not about a row.
        abort_unless($this->currentUser($request)->can(Permissions::BILLING_AUDIT_VIEW), 403);

        $entries = ActivityEntry::query()
            ->whereIn('subject_type', BillingAuditSubjects::types())
            // Morph-eager-loaded: a Resource runs once per row, so reading the
            // subject's uuid inside one is an N+1 by construction — the lesson
            // ClassSessionResource paid for in 005.
            ->with(['subject', 'causer'])
            ->latest('id')
            ->paginate(50);

        return response()->json(
            BillingAuditEntryResource::collection($entries)->response()->getData(true)
        );
    }

    /**
     * The whole chain behind one payment (FR-028).
     *
     * money → order → purchase → ledger entry → the lot it opened → every
     * consumption drawn from it. The question an auditor actually asks is not
     * "was this approved" but "what became of it", and no single table answers
     * that.
     *
     * ⚠️ A FIXED NUMBER OF QUERIES, WHATEVER THE CHAIN'S LENGTH. Every step is
     * one statement and the allocations arrive with their consumption already
     * eager-loaded; a walk that resolved each allocation's entry inside the loop
     * would be an N+1 whose N is "how many sessions this student has taken",
     * which grows for exactly the students an auditor looks at.
     */
    public function show(Request $request, string $transaction): JsonResponse
    {
        abort_unless($this->currentUser($request)->can(Permissions::BILLING_AUDIT_VIEW), 403);

        $payment = PaymentTransaction::query()
            ->withoutWorkspaceScope()
            // ⚠️ THE BYPASS IS PER MODEL, AND AN EAGER LOAD IS ITS OWN QUERY. A
            // bare `->with('order')` runs Order's global scope inside the
            // relation, so a payment from any workspace but the reader's
            // fallback comes back with a null order — and the whole chain below
            // it silently answers "nothing was bought". Found while writing the
            // collection report, which walks the same tables.
            ->with(['order' => fn ($query) => $query->withoutWorkspaceScope()])
            ->where('uuid', $transaction)
            ->firstOrFail();

        $order = $payment->order;

        $purchase = $order === null ? null : CreditPurchase::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $order->getKey())
            ->first();

        // ⚠️ ALL FOUR INDEXED COLUMNS, and not a relation. `credit_tx_idempotency`
        // is `(credit_balance_id, type, source_type, source_id)` and the balance
        // has to lead it; a relation cannot carry that key across an eager load,
        // and one without it scans the whole ledger. See CreditPurchase.
        $entry = $purchase === null ? null : CreditTransaction::query()
            ->withoutWorkspaceScope()
            ->where('credit_balance_id', $purchase->credit_balance_id)
            ->where('type', CreditTransactionType::Purchase->value)
            ->where('source_type', 'credit_purchase')
            ->where('source_id', $purchase->getKey())
            ->first();

        $lot = $entry === null ? null : CreditLot::query()
            ->withoutWorkspaceScope()
            ->where('credit_transaction_id', $entry->getKey())
            ->first();

        // `whereIn` over a possibly-empty list rather than a branch returning a
        // different collection type: a payment with no lot costs one refused
        // query and keeps the shape below single.
        $allocations = CreditAllocation::query()
            ->with('consumption')
            ->whereIn('lot_transaction_id', $lot === null ? [] : [$lot->credit_transaction_id])
            ->get();

        return response()->json(['data' => [
            'payment' => [
                'uuid' => $payment->uuid,
                'status' => $payment->status->value,
                'provider' => $payment->provider,
                'method' => $payment->method?->value,
                'settled_at' => $payment->settled_at?->toIso8601String(),
            ],
            'order_uuid' => $order?->uuid,
            'credits_purchased' => $entry?->credits,
            'credits_remaining_in_lot' => $lot?->credits_remaining,
            'consumptions' => $allocations->map(fn (CreditAllocation $allocation): array => [
                'credits' => $allocation->credits,
                'entry_uuid' => $allocation->consumption?->uuid,
                'occurred_at' => $allocation->created_at?->toIso8601String(),
            ])->values(),
            // The decisions taken about this payment and its order, in the same
            // shape the list uses.
            'trail' => BillingAuditEntryResource::collection($this->trailFor($payment)),
        ]]);
    }

    /**
     * Audit entries about this payment and the order it belongs to.
     *
     * ⚠️ ONE STATEMENT FOR BOTH SUBJECTS, not one per subject: the trail is read
     * beside a chain that may already have cost four queries, and "two subjects"
     * is exactly the shape that becomes "seven subjects" the next time somebody
     * extends it.
     *
     * @return Collection<int, ActivityEntry>
     */
    private function trailFor(PaymentTransaction $payment): Collection
    {
        // Both subjects always: `payment_transactions.order_id` is NOT NULL, so a
        // branch on it would be a branch that never runs — and a reader would
        // spend time working out when it did.
        $subjects = [
            PaymentTransaction::class => $payment->getKey(),
            Order::class => $payment->order_id,
        ];

        return ActivityEntry::query()
            ->where(function (Builder $query) use ($subjects): void {
                foreach ($subjects as $type => $id) {
                    $query->orWhere(fn (Builder $inner) => $inner
                        ->where('subject_type', $type)
                        ->where('subject_id', $id));
                }
            })
            ->with(['subject', 'causer'])
            ->oldest('id')
            ->get();
    }
}
