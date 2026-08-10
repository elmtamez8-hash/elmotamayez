<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Withholding for a whole list of balances, in a fixed number of queries.
 *
 * The predicate itself lives in {@see CreditLedger} and is not repeated here —
 * this class only gathers its inputs for the whole list at once: the billing mode
 * and whether an exam window is open, per workspace, and whether the current
 * deferred-payment terms have been accepted, per student.
 *
 * A per-row read would be an N+1 by construction, and NFR-012 gives the whole
 * panel a fixed query budget. It is the same reason AccountStanding carries a
 * bulk method and is forbidden inside a Resource.
 */
class WithholdingReader
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly BillingSettings $settings,
        private readonly ConsentRegistry $consent,
    ) {}

    /**
     * Stamp `is_withheld` and `credits_needed` onto each balance and return them.
     *
     * ⚠️ THE DEFICIT IS STAMPED HERE BECAUSE THIS IS WHERE THE FLOOR IS KNOWN.
     * It used to be recomputed by AccountStanding beside the stamp, from
     * `floorForBalance()` with the exam-window flag left at its default — so
     * during a window the block was computed against a floor of zero and the
     * quote against the ceiling that window had just suspended. The student was
     * told "تحتاج 1", bought one, and was refused again; the same number is
     * printed by the booking refusal, the playback refusal and the withheld
     * notification, so one line was wrong in three places.
     *
     * Two answers to one question is the shape of the defect, not the arithmetic.
     * There is one floor per balance and it is computed once, right here.
     *
     * @param  Collection<int, CreditBalance>  $balances
     * @return Collection<int, CreditBalance>
     */
    public function stamp(Collection $balances): Collection
    {
        if ($balances->isEmpty()) {
            return $balances;
        }

        $workspaceIds = $balances->pluck('workspace_id')->unique()->all();

        $inExamMode = ExamModeWindow::query()
            ->withoutWorkspaceScope()
            ->whereIn('workspace_id', $workspaceIds)
            ->covering(now())
            ->pluck('workspace_id')
            ->unique()
            ->flip();

        /*
         * ⚠️ THE WORKSPACES ARE FETCHED HERE, and reading them off the balances
         * instead is the N+1 this class exists to prevent. Both inputs to the
         * predicate — the billing mode and the zero-balance behaviour — are read
         * from a Workspace, so `$balance->workspace` inside the loop lazy-loads
         * one row per balance. It cost 26 queries for 40 rows against 9 for four,
         * which is exactly what BalanceQueryBudgetTest compares.
         *
         * That is also why the explicit-argument forms of the predicate exist:
         * the resolving forms are right for a single balance and wrong for a
         * list, and having both is what lets this loop stay flat.
         */
        $workspaces = Workspace::query()->whereIn('id', $workspaceIds)->get()->keyBy('id');

        /*
         * The third input, and it costs a query only when somebody has a ceiling
         * (FR-048 · FR-049). Balances at zero are filtered out first because their
         * floor is zero whatever the answer is — which, with the feature switched
         * off at launch, is every balance on the platform and therefore no query
         * at all.
         */
        $consented = $this->consent->forStudentIds(
            array_values(
                $balances->where('credit_limit_credits', '>', 0)
                    ->pluck('student_user_id')
                    ->unique()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            ),
            ConsentDocument::DeferredPaymentTerms,
        );

        return $balances->each(function (CreditBalance $balance) use ($inExamMode, $workspaces, $consented): void {
            $workspace = $workspaces->get($balance->workspace_id);

            if ($workspace === null) {
                // No workspace, no mode to read. Not withheld rather than
                // withheld: a balance whose tenant vanished is a broken row, and
                // locking its owner out is punishing them for it.
                $balance->setAttribute('is_withheld', false);
                $balance->setAttribute('credits_needed', 0);

                return;
            }

            $floor = $this->ledger->floorFor(
                $balance,
                $this->settings->mode($workspace),
                $inExamMode->has($balance->workspace_id),
                isset($consented[$balance->student_user_id]),
            );

            $withheld = $this->ledger->isBlocked(
                $balance,
                $floor,
                $this->settings->zeroBalanceBehavior($workspace),
            );

            $balance->setAttribute('is_withheld', $withheld);

            // What it would take to afford ONE more session: the distance from
            // here up to the floor, plus that session. Never the raw negative
            // balance — a student with a ceiling owes less than their balance
            // reads, and quoting the debt would ask for credits they do not need.
            $balance->setAttribute(
                'credits_needed',
                $withheld ? max(1, $floor + 1 - $balance->remaining_credits) : 0,
            );
        });
    }
}
