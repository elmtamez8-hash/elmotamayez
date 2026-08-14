<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Data\CollectionFilter;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

/**
 * What was collected in a period: by method, by status, by source (FR-031).
 *
 * ⚠️ `withoutWorkspaceScope()`, DECLARED RATHER THAN INHERITED. `BILLING_COLLECTION_VIEW`
 * is a PLATFORM permission, but `payment_transactions` carries `BelongsToWorkspace`
 * and `WorkspaceContext::id()` falls back to `users.last_workspace_id` for EVERY
 * user — a super admin included. Left scoped, this report would quietly show one
 * teacher's money as the platform's total, and pass its own test on a fixture
 * with one workspace. `CollectionReportTest` seeds two for that reason.
 *
 * ⚠️ AND THE BYPASS IS PER MODEL, INCLUDING INSIDE AN EAGER LOAD. A bare
 * `->with('order')` runs Order's global scope in the relation query and returns
 * null for every row outside the caller's fallback workspace — the same silent
 * single-workspace answer wearing a different shape. Every closure below repeats
 * it.
 *
 * ⚠️ ONE GROUPED STATEMENT ANSWERS ALL THREE BREAKDOWNS. Method, status and
 * source are the marginals of a single joint grouping, and a marginal summed
 * from a joint distribution is exact — three separate `GROUP BY`s over the
 * fastest-growing table in the product would be three scans to learn what one
 * already said.
 *
 * ⚠️ «المصدر» IS THE ORDER'S KIND — course or credits — and that is an
 * assumption this Action declares rather than hides: `provider` would answer the
 * question too, but `method` already carries the gateway/manual split, so
 * grouping by provider would be the same cut twice and the report would lose the
 * one dimension that says what the money BOUGHT.
 *
 * ⚠️ CURRENCY IS PART OF EVERY KEY. The spec's edge cases include a payment in a
 * currency other than the debt's, and a total that adds QAR to anything else
 * matches no source on earth — which is the whole of `SC-010`.
 */
class BuildCollectionReport extends Action
{
    /**
     * Totals plus one page of detail.
     *
     * @return array{summary: array<string, mixed>, rows: LengthAwarePaginator<int, PaymentTransaction>}
     */
    public function handle(CollectionFilter $filter, int $perPage = 50): array
    {
        return [
            'summary' => $this->summary($filter),
            'rows' => $this->rows($filter)->paginate($perPage),
        ];
    }

    /**
     * Every row in the period, for the export (FR-034).
     *
     * ⚠️ THE SAME BUILDER THE SCREEN USES, never a second query written beside
     * it: "the same data and the same restrictions" is a promise that a
     * re-derived query breaks the first time somebody forgets one clause, and
     * the file is the artefact that leaves the building.
     *
     * ⚠️ `lazyById`, NOT `lazy()` OR `chunk()`. Ten thousand rows is the sizing
     * the spec gives, and OFFSET pagination over a growing table skips rows the
     * moment one is inserted mid-export.
     *
     * @return LazyCollection<int, PaymentTransaction>
     */
    public function stream(CollectionFilter $filter): LazyCollection
    {
        return $this->rows($filter)->lazyById(500);
    }

    /**
     * @return Builder<PaymentTransaction>
     */
    private function rows(CollectionFilter $filter): Builder
    {
        return PaymentTransaction::query()
            ->withoutWorkspaceScope()
            /*
            | ⚠️ THE CLOSURE'S ARGUMENT IS THE RELATION, NOT A BUILDER, and it is
            | left untyped for that reason — the same shape `GetStudentSchedule`
            | uses. A `Builder` hint here is a fatal error the first time the
            | report is opened, and a `BelongsTo` hint fails static analysis,
            | which expects a callable wide enough for any relation.
            */
            ->with([
                'order' => fn ($query) => $query->withoutWorkspaceScope()->with('user'),
                // The price snapshot, eager-loaded through `credit_purchases(order_id)`
                // — the index the reporting migration added. Row by row this is
                // the N+1 whose N is "how many payments the period took".
                'purchase' => fn ($query) => $query->withoutWorkspaceScope(),
            ])
            ->tap(fn (Builder $query) => $this->constrain($query, $filter))
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(CollectionFilter $filter): array
    {
        /*
        | The query builder, not Eloquent: a grouped aggregate returns no models,
        | so there is nothing for a global scope to be bypassed ON — and reaching
        | for `PaymentTransaction::query()` here would suggest the rows come back
        | as models when they come back as sums.
        */
        $groups = DB::table('payment_transactions')
            ->join('orders', 'orders.id', '=', 'payment_transactions.order_id')
            ->select([
                'payment_transactions.method',
                'payment_transactions.status',
                'payment_transactions.currency',
                'orders.kind as source',
            ])
            ->selectRaw('COUNT(*) as transactions')
            ->selectRaw('SUM(payment_transactions.amount_minor) as amount_minor')
            ->tap(fn (QueryBuilder $query) => $this->constrain($query, $filter))
            ->groupBy('payment_transactions.method', 'payment_transactions.status', 'payment_transactions.currency', 'orders.kind')
            ->get();

        return [
            'from' => $filter->from->toIso8601String(),
            // The inclusive day the caller asked for, given back as they wrote
            // it. The exclusive bound is an implementation detail of the query,
            // and echoing it would read as an off-by-one to everyone but its
            // author.
            'to' => $filter->to->subDay()->toIso8601String(),
            'by_method' => $this->marginal($groups, 'method'),
            'by_status' => $this->marginal($groups, 'status'),
            'by_source' => $this->marginal($groups, 'source'),
            'totals' => $this->marginal($groups, null),
        ];
    }

    /**
     * Collapse the joint grouping onto one dimension, currency always kept.
     *
     * @param  Collection<int, stdClass>  $groups
     * @return list<array<string, mixed>>
     */
    private function marginal(Collection $groups, ?string $dimension): array
    {
        $totals = [];

        foreach ($groups as $group) {
            /** @var array<string, mixed> $row */
            $row = (array) $group;

            $currency = (string) $row['currency'];
            $key = $dimension === null ? null : ($row[$dimension] === null ? null : (string) $row[$dimension]);
            $index = $currency.'|'.($key ?? '');

            $totals[$index] ??= ['key' => $key, 'currency' => $currency, 'transactions' => 0, 'amount_minor' => 0];
            $totals[$index]['transactions'] += (int) $row['transactions'];
            $totals[$index]['amount_minor'] += (int) $row['amount_minor'];
        }

        return array_values($totals);
    }

    /**
     * The period and the narrowing — written once for both readers.
     *
     * ⚠️ HALF-OPEN, AND NEVER `whereDate()`. `>=` on the lower bound and `<` on
     * the upper: closed at both ends counts the boundary second in two reports,
     * and a function wrapping `created_at` costs the report the composite index
     * `SC-014` exists to use.
     *
     * @param  Builder<PaymentTransaction>|QueryBuilder  $query
     */
    private function constrain(Builder|QueryBuilder $query, CollectionFilter $filter): void
    {
        $query
            ->where('payment_transactions.created_at', '>=', $filter->from)
            ->where('payment_transactions.created_at', '<', $filter->to);

        if ($filter->method !== null) {
            $query->where('payment_transactions.method', $filter->method->value);
        }

        if ($filter->status !== null) {
            $query->where('payment_transactions.status', $filter->status->value);
        }
    }
}
