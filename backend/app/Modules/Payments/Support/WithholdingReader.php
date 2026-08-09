<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\ExamModeWindow;
use Illuminate\Support\Collection;

/**
 * Withholding for a whole list of balances, in a fixed number of queries.
 *
 * The predicate itself lives in {@see CreditLedger} and is not repeated here —
 * this class only gathers its two per-workspace inputs, the billing mode and
 * whether an exam window is open, for every workspace in the list at once.
 *
 * A per-row read would be an N+1 by construction, and NFR-012 gives the whole
 * panel a fixed query budget. It is the same reason AccountStanding carries a
 * bulk method and is forbidden inside a Resource.
 */
class WithholdingReader
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * Stamp `is_withheld` onto each balance and return them.
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

        return $balances->each(function (CreditBalance $balance) use ($inExamMode): void {
            $floor = $this->ledger->floorForBalance(
                $balance,
                $inExamMode->has($balance->workspace_id),
            );

            $balance->setAttribute('is_withheld', $this->ledger->isBlockedForBalance($balance, $floor));
        });
    }
}
