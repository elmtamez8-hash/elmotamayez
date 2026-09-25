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
use App\Shared\Traits\LogsActivity;
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
    use LogsActivity;

    public function __construct(
        private readonly SettlementWindow $window,
    ) {}

    /** @return SettlementPeriod|null the closed period, or null if someone else closed it first */
    public function handle(SettlementPeriod $period, ?User $by = null): ?SettlementPeriod
    {
        /*
        | ⛔ THE CLAIM IS INSIDE THE TRANSACTION, WITH THE ARITHMETIC. It used to
        | commit on its own first: a throw anywhere in the stamping left the period
        | `closed` with no units stamped and no totals frozen, and every retry lost
        | its own claim («someone else closed it») — a teacher's month shut at
        | zero for ever, with nothing left that could finish it. The lesson
        | `claimForGrading()` taught in assessments, reached from settlement.
        |
        | Inside, a failure rolls the claim back with everything else and the
        | same close runs again. A concurrent second caller still loses cleanly:
        | it waits on the row the winner claimed and then updates zero rows.
        */
        $closed = DB::transaction(function () use ($period, $by): ?SettlementPeriod {
            // The claim, before anything is computed. Whoever wins this row does
            // the arithmetic; whoever loses does nothing at all.
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

            // Re-read, because the conditional UPDATE above wrote round the
            // instance: the in-memory copy still says `open`.
            $fresh = $period->refresh();

            $this->stampUnits($fresh);
            $this->stampEntries($fresh);
            $this->freezeTotals($fresh);

            return $fresh;
        });

        if ($closed === null) {
            return null;
        }

        // Logged AFTER the totals are frozen, so the audit entry carries the
        // numbers that were actually written rather than the ones that were
        // about to be. Only the winner of the conditional UPDATE gets here, so
        // there is exactly one entry per close however many callers tried.
        $this->logActivity('settlement.period.closed', $closed, [
            'units_count' => $closed->units_count,
            'net_minor' => $closed->net_minor,
            'carried_out_minor' => $closed->carried_out_minor,
        ]);

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

        $this->stampReversals($period);
    }

    /**
     * Claim the corrections whose original has already been paid for — or is
     * being paid for by this very close.
     *
     * ⚠️ A REVERSAL WAS NEVER CLAIMED, SO IT NEVER REDUCED ANYBODY'S PAY. It is
     * written with `status = reversed`, which the `accrued` filter above can never
     * see, and its negative ledger line hangs off the REVERSAL row's id — so
     * `stampEntries()` did not take it either. The −amount sat unstamped for
     * ever, outside every `freezeTotals()`, and the teacher was paid the unit in
     * full. (The open-window statement did show it, which is how two screens
     * disagreed about one hour.)
     *
     * NO DATE FILTER, for the reason a deduction has none: a correction is typed
     * AFTER the fact, and its `delivered_at` is copied from the original — so a
     * window filter on it would drop every reversal of an hour already closed,
     * i.e. exactly the ones that matter. It lands in the first close after it was
     * written, like any other adjustment.
     *
     * ⚠️ BUT ONLY ONCE ITS ORIGINAL IS STAMPED. A correction of a unit still
     * waiting for its package, or delivered after this window, travels with that
     * unit: claiming it now would take the money off this teacher in one period
     * and hand it back in a later one, with a carried shortfall in between that
     * nothing actually owed. `stampUnits()` runs first, so an original settled by
     * this same close already counts.
     *
     * The status stays `reversed` — only the period is written — because it is
     * what the statement reads to keep a correction out of the session counts.
     *
     * Discovery and write are two statements, ids passed as values:
     * `UPDATE teaching_units … WHERE reversal_of_id IN (SELECT id FROM
     * teaching_units …)` is MySQL ERROR 1093, which SQLite rewrites silently and
     * therefore never reports.
     */
    private function stampReversals(SettlementPeriod $period): void
    {
        $candidates = TeachingUnit::query()
            ->where('teacher_profile_id', $period->teacher_profile_id)
            ->whereNull('settlement_period_id')
            ->where('status', TeachingUnitStatus::Reversed->value)
            ->where('reversal_of_id', '!=', TeachingUnit::NOT_A_REVERSAL)
            ->pluck('reversal_of_id', 'id');

        if ($candidates->isEmpty()) {
            return;
        }

        $stampedOriginals = [];

        foreach (array_chunk(array_values(array_unique($candidates->all())), 1000) as $chunk) {
            foreach (TeachingUnit::query()
                ->whereIn('id', $chunk)
                ->whereNotNull('settlement_period_id')
                ->pluck('id') as $id) {
                $stampedOriginals[(int) $id] = true;
            }
        }

        $claimable = $candidates
            ->filter(static fn (mixed $originalId): bool => isset($stampedOriginals[(int) $originalId]))
            ->keys()
            ->all();

        foreach (array_chunk($claimable, 1000) as $chunk) {
            TeachingUnit::query()
                ->whereIn('id', $chunk)
                // Repeated, so a row another close claimed in between is not
                // claimed twice.
                ->whereNull('settlement_period_id')
                ->update([
                    'settlement_period_id' => $period->getKey(),
                    'settled_at' => now(),
                ]);
        }
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
     * or a bonus, which have no unit behind them. A reversal's negative line
     * rides in on its reversal row, which `stampReversals()` has just claimed.
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
        // Originals only. A correction is stamped for its money, not as a second
        // hour taught — counting it would report more work than was delivered,
        // the reason the statement already leaves it out of its own counts.
        $unitsCount = TeachingUnit::query()
            ->where('settlement_period_id', $period->getKey())
            ->where('reversal_of_id', TeachingUnit::NOT_A_REVERSAL)
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
