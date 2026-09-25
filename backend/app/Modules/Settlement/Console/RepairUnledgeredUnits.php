<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Console;

use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use App\Modules\Settlement\Listeners\RecordUnitInLedger;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\TeachingUnit;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Teaching units that became money with no ledger line behind them.
 *
 * ⚠️ WHERE THEY CAME FROM. Before `AccrueUnitsOnDelivery` wrapped the units and
 * their ledger lines in one transaction, a throw half-way left some units on disk
 * as `accrued` with no `ledger_entries` row — and the retry could not repair it,
 * because the unit's own unique index answered «already accrued» and nothing was
 * dispatched for it. The teacher was short that hour's pay for ever, while the
 * unit row insisted it had been counted. `ReleasePendingUnits` (claim, then
 * dispatch) and `ReverseTeachingUnit` (create, then dispatch) are still two
 * statements each, so the same shape can in principle still arise from them.
 *
 * ⚠️ THE ENTRY IS WRITTEN BY `RecordUnitInLedger`, THE LISTENER ITSELF — the one
 * writer every other route goes through — so the sign rule, the zero-amount rule
 * and the entry type are the ones production already uses. It is called directly
 * rather than by dispatching `TeachingUnitAccrued`, so nothing else that may one
 * day listen to that event fires a second time for an hour it already saw.
 *
 * ⚠️ THERE IS NO UNIQUE INDEX ON `ledger_entries.teaching_unit_id`, so this
 * command is idempotent only by its own predicate. Three things hold it:
 *  - the `NOT EXISTS` in discovery, re-asked inside the per-unit transaction
 *    immediately before the write;
 *  - an AGE FLOOR on `accrued_at`, because the two live writers above leave a
 *    unit entry-less for the milliseconds between their two statements — exactly
 *    the row this would otherwise pick up and pay a second time;
 *  - a cache lock, so two operators running it at once do not race each other.
 * That is also why it is a command and not a scheduled sweep: an unattended
 * runner beside the live writers, with no index underneath, is one overlap away
 * from paying an hour twice.
 *
 * ⚠️ ONLY UNITS NOT YET IN A PERIOD ARE REPAIRED. A unit already stamped with a
 * closed period has a frozen `net_minor` behind it, and `CloseSettlementPeriod::
 * stampEntries()` claims only entries whose unit is stamped by THAT close — so an
 * entry written now for such a unit would never be claimed by any close and never
 * paid, while inflating the running balance. Those are REPORTED, with their
 * period, for the owner to decide on; nothing here writes them.
 *
 * Zero-amount units are excluded: `RecordUnitInLedger` deliberately writes no line
 * for them, so a zero unit with no entry is correct, not stranded. Pending and
 * disputed units have no entry by design. A reversal matches on its OWN id.
 */
class RepairUnledgeredUnits extends Command
{
    protected $signature = 'settlement:repair-unledgered-units
        {--dry-run : اعرض الأعداد فقط ولا تكتب شيئاً}
        {--min-age=15 : تجاهل الوحدات التي صارت مستحقّةً منذ أقلّ من هذا العدد من الدقائق}';

    protected $description = 'اكتب قيدَ الدفتر الناقص لوحدات التدريس المستحقّة التي لا قيدَ لها (قبل تصحيح ذرّيّة الاستحقاق)';

    private const LOCK = 'settlement:repair-unledgered-units';

    public function handle(RecordUnitInLedger $recorder): int
    {
        $minAge = max(0, (int) $this->option('min-age'));
        $cutoff = now()->subMinutes($minAge);
        $dryRun = (bool) $this->option('dry-run');

        $repairable = $this->repairable($cutoff);
        $stranded = $this->stranded($cutoff);

        $repairableCount = (clone $repairable)->count();
        $repairableAmount = (int) (clone $repairable)->sum('amount_minor');
        $strandedCount = (clone $stranded)->count();

        $this->line("وحدات بلا قيد وقابلة للإصلاح: {$repairableCount} (المجموع بالوحدات الصغرى: {$repairableAmount})");
        $this->line("وحدات بلا قيد داخل فترة مُغلقة (للتقرير فقط، لا تُكتب): {$strandedCount}");

        if ($strandedCount > 0) {
            $this->table(
                ['id', 'الفترة', 'الحالة', 'المبلغ'],
                (clone $stranded)
                    ->orderBy('id')
                    ->limit(200)
                    ->get(['id', 'settlement_period_id', 'status', 'amount_minor'])
                    ->map(static fn (TeachingUnit $unit): array => [
                        $unit->getKey(),
                        $unit->settlement_period_id,
                        $unit->status->value,
                        $unit->amount_minor,
                    ])
                    ->all(),
            );
        }

        if ($dryRun) {
            $this->info('تشغيلٌ تجريبيّ: لم يُكتب شيء.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            $this->error('تشغيلٌ آخرُ لهذا الأمر جارٍ الآن.');

            return self::FAILURE;
        }

        $written = 0;

        try {
            // Keyset, never offset: every repair removes its row from the
            // predicate, so an OFFSET walk would skip exactly the rows the last
            // page fixed.
            $this->repairable($cutoff)
                ->select('teaching_units.*')
                ->lazyById(500, 'teaching_units.id', 'id')
                ->each(function (TeachingUnit $unit) use ($recorder, $cutoff, &$written): void {
                    $written += $this->repairOne($unit, $recorder, $cutoff) ? 1 : 0;
                });
        } finally {
            $lock->release();
        }

        Log::info('settlement.unledgered_units.repaired', [
            'written' => $written,
            'stranded_in_closed_period' => $strandedCount,
        ]);

        $this->info("كُتب {$written} قيداً.");

        return self::SUCCESS;
    }

    /**
     * Re-asked inside the transaction, because discovery and write are two
     * statements and the unit may have been claimed or ledgered in between.
     */
    private function repairOne(TeachingUnit $unit, RecordUnitInLedger $recorder, CarbonInterface $cutoff): bool
    {
        return DB::transaction(function () use ($unit, $recorder, $cutoff): bool {
            $fresh = $this->repairable($cutoff)->whereKey($unit->getKey())->first();

            if ($fresh === null) {
                return false;
            }

            $recorder->handle(new TeachingUnitAccrued($fresh));

            return LedgerEntry::query()
                ->withoutWorkspaceScope()
                ->where('teaching_unit_id', $fresh->getKey())
                ->exists();
        });
    }

    /**
     * An earning, not in any period, older than the floor, with no ledger line.
     *
     * @return Builder<TeachingUnit>
     */
    private function repairable(CarbonInterface $cutoff): Builder
    {
        return $this->unledgered($cutoff)
            ->whereIn('status', [
                TeachingUnitStatus::Accrued->value,
                TeachingUnitStatus::Reversed->value,
            ])
            ->whereNull('settlement_period_id');
    }

    /** @return Builder<TeachingUnit> */
    private function stranded(CarbonInterface $cutoff): Builder
    {
        return $this->unledgered($cutoff)
            ->whereIn('status', [
                TeachingUnitStatus::Accrued->value,
                TeachingUnitStatus::Settled->value,
                TeachingUnitStatus::Reversed->value,
            ])
            ->whereNotNull('settlement_period_id');
    }

    /**
     * ⚠️ withoutWorkspaceScope: a platform-wide repair. A console command has no
     * context today, but a scope that ever resolved one would silently narrow
     * this to one teacher.
     *
     * @return Builder<TeachingUnit>
     */
    private function unledgered(CarbonInterface $cutoff): Builder
    {
        return TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('amount_minor', '!=', 0)
            ->whereNotNull('accrued_at')
            ->where('accrued_at', '<', $cutoff)
            ->whereNotExists(static fn (QueryBuilder $query) => $query
                ->selectRaw('1')
                ->from('ledger_entries')
                ->whereColumn('ledger_entries.teaching_unit_id', 'teaching_units.id'));
    }
}
