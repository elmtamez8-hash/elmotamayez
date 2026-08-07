<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Events\SettlementPeriodClosed;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\SettlementWindow;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The moment a teacher's total stops moving.
 *
 * Closing is one atomic `UPDATE … WHERE status = 'open'` and a check of the
 * affected rows — never `count()` then write, which is the definition of the
 * race, and never `lockForUpdate()`, which is a **no-op on SQLite**: a test built
 * around it passes locally and proves nothing about the MySQL it will run on.
 * The second caller loses the UPDATE and gets `null` (FR-028 · SC-014).
 *
 * The totals are frozen on the row rather than derived on read, because a total
 * that recomputes gives a different answer after any later correction — including
 * to a teacher who has already been paid against the old one.
 */
class CloseSettlementPeriod extends Action
{
    public function __construct(
        private readonly SettlementWindow $window,
    ) {}

    /** @return SettlementPeriod|null the closed period, or null if someone else closed it first */
    public function handle(SettlementPeriod $period, ?User $by = null): ?SettlementPeriod
    {
        // The claim, before anything is computed. Whoever wins this row does the
        // arithmetic; whoever loses does nothing at all.
        $claimed = SettlementPeriod::query()
            ->whereKey($period->getKey())
            ->where('status', SettlementPeriodStatus::Open->value)
            ->update([
                'status' => SettlementPeriodStatus::Closed->value,
                'closed_at' => now(),
                // Null when the schedule closed it, which is the honest value:
                // no person made this decision.
                'closed_by' => $by?->getKey(),
            ]);

        if ($claimed === 0) {
            return null;
        }

        $closed = DB::transaction(function () use ($period): SettlementPeriod {
            // Re-read, because the conditional UPDATE above wrote round the
            // instance: the in-memory copy still says `open`.
            $fresh = $period->refresh();

            $this->stampUnits($fresh);
            $this->stampEntries($fresh);
            $this->freezeTotals($fresh);

            return $fresh;
        });

        SettlementPeriodClosed::dispatch($closed);

        return $closed;
    }

    /**
     * Claim the window's settleable units.
     *
     * `< ends_on + 1 day`, not `<= ends_on`: `ends_on` is a DATE and
     * `delivered_at` a timestamp, so the second form binds midnight and silently
     * drops every unit taught ON the closing day. The same boundary already cost
     * `FreezePeriod::covering()` a fix in 005.
     *
     * Only `accrued` units are claimed. A disputed one is deliberately left
     * behind (FR-008): closing over it pays a claim nobody has resolved, and
     * reopening the period to take it back is forbidden. A unit that arrives
     * late for a window already shut stays unclaimed and lands in the next one
     * (FR-024) — that is what "carried forward" means mechanically.
     */
    private function stampUnits(SettlementPeriod $period): void
    {
        TeachingUnit::query()
            ->where('teacher_profile_id', $period->teacher_profile_id)
            ->whereNull('settlement_period_id')
            ->where('status', TeachingUnitStatus::Accrued->value)
            ->where('delivered_at', '<', $this->exclusiveEnd($period))
            ->update([
                'settlement_period_id' => $period->getKey(),
                'status' => TeachingUnitStatus::Settled->value,
                'settled_at' => now(),
            ]);
    }

    /**
     * Claim the matching ledger entries.
     *
     * A BULK update, and that is the one sanctioned write to a ledger row after
     * insertion. Eloquent retrieves no models for a bulk update, so the
     * append-only guard in `LedgerEntry::booted()` does not fire — which is
     * correct here and stays correct because nothing else does this. The guard
     * still refuses every per-instance edit, and a test pins that.
     *
     * Entries are claimed by their unit, plus every standalone line — a deduction
     * or a bonus, which have no unit behind them.
     *
     * Standalone lines carry NO date filter, deliberately. A unit has a
     * `delivered_at` that says which window it belongs to; an administrative
     * adjustment has only the day someone typed it, and that day is almost always
     * AFTER the window ended, because closing happens after the window ends. A
     * date filter here would push every deduction into the following period —
     * silently paying in full a window that was supposed to be reduced.
     * Unstamped means "the open window", and for these lines that is the whole
     * definition.
     */
    private function stampEntries(SettlementPeriod $period): void
    {
        $unitIds = TeachingUnit::query()
            ->where('settlement_period_id', $period->getKey())
            ->pluck('id');

        LedgerEntry::query()
            ->where('teacher_profile_id', $period->teacher_profile_id)
            ->whereNull('settlement_period_id')
            ->where(fn ($query) => $query
                ->whereIn('teaching_unit_id', $unitIds)
                ->orWhereNull('teaching_unit_id'))
            ->update(['settlement_period_id' => $period->getKey()]);
    }

    /**
     * Freeze the four numbers, computed from the rows just stamped.
     *
     * From the stamped set and nothing else: a total computed from a separate
     * query is a total that can describe a different set of rows than the one it
     * claims to, and the difference would only ever show up as a teacher's pay.
     */
    private function freezeTotals(SettlementPeriod $period): void
    {
        $unitsCount = TeachingUnit::query()
            ->where('settlement_period_id', $period->getKey())
            ->count();

        $entries = LedgerEntry::query()
            ->where('settlement_period_id', $period->getKey())
            ->get(['amount_minor']);

        $gross = $entries->sum(fn (LedgerEntry $e): int => max(0, $e->amount_minor));
        $deductions = $entries->sum(fn (LedgerEntry $e): int => min(0, $e->amount_minor));

        $previous = $this->window->lastClosed((int) $period->teacher_profile_id, (int) $period->getKey());

        // Explicit rather than `?->carried_out_minor ?? 0`: `??` already swallows
        // the access on null, so the nullsafe there reads as a guard that guards
        // nothing. A teacher's first period really has no predecessor.
        $carriedIn = $previous === null ? 0 : $previous->carried_out_minor;

        $net = $gross + $deductions + $carriedIn;

        $period->forceFill([
            'units_count' => $unitsCount,
            'gross_minor' => $gross,
            'deductions_minor' => $deductions,
            'carried_in_minor' => $carriedIn,
            'net_minor' => $net,
            // FR-026 — a shortfall travels to the next window rather than being
            // forgiven or, worse, paid. A surplus carries nothing: it is about to
            // be paid out, and carrying it too would pay it twice.
            'carried_out_minor' => $net < 0 ? $net : 0,
        ])->save();
    }

    /** The first instant NOT in the window. See stampUnits() for why it is a day later. */
    private function exclusiveEnd(SettlementPeriod $period): CarbonImmutable
    {
        return CarbonImmutable::parse($period->ends_on->toDateString())->addDay()->startOfDay();
    }
}
