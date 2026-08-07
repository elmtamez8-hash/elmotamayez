<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Data\TeacherStatement;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\SettlementSettings;
use App\Modules\Settlement\Support\SettlementWindow;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the teacher is owed for the window that has not closed yet.
 *
 * **The money comes from the ledger, not from the units**, and that is a
 * deliberate reading of research §R8 rather than a departure from it. R8's
 * concern is which ROWS are scanned — frozen totals for a closed period, an
 * indexed aggregate for the open one — and both hold here. But a total derived
 * from `teaching_units` can only ever see units: `LedgerEntryType` also carries
 * `Deduction` and `Bonus`, which have no unit behind them, so a units-derived
 * gross would silently omit the first manual adjustment anyone writes and
 * FR-022's "zero difference" would be false the same day. The ledger is the
 * balance (NFR-009); the statement reads it.
 *
 * The unit table is still read — for COUNTS. How many students, how many units
 * of which kind, how many still waiting on a package. Counts and money come from
 * different tables because they answer different questions, and neither can
 * drift into the other.
 *
 * Every query here is constant in number: eight, whether the teacher has ten
 * units or ten thousand. `QueryBudgetTest` measures that by comparing two sizes
 * rather than against a fixed allowance, because a fixed allowance is where an
 * N+1 hides until production.
 */
class BuildTeacherStatement extends Action
{
    public function __construct(
        private readonly SettlementWindow $window,
        private readonly SettlementSettings $settings,
    ) {}

    public function handle(TeacherProfile $teacher): TeacherStatement
    {
        $teacherId = (int) $teacher->getKey();

        // The closed period immediately behind this one. Its frozen carry-out is
        // this window's opening balance — read, never recomputed, because a total
        // that recomputes gives a different answer after any later correction,
        // including to a teacher already paid against the old one.
        $lastClosed = $this->window->lastClosed($teacherId);
        $open = $this->window->open($teacherId);

        // Through SettlementWindow, not derived here: the closing job asks the
        // same question, and two derivations that disagree by a day would freeze
        // a total over one set of days while the statement showed another.
        [$startsOn, $endsOn] = $this->window->bounds($teacherId, $open, $lastClosed);

        $carriedIn = $lastClosed === null ? 0 : $lastClosed->carried_out_minor;
        $money = $this->money($teacherId, $carriedIn);

        return new TeacherStatement(
            periodUuid: $open === null ? null : (string) $open->uuid,
            startsOn: $startsOn->toDateString(),
            endsOn: $endsOn->toDateString(),
            status: SettlementPeriodStatus::Open,
            studentsCount: $this->studentsCount($teacherId),
            unitCounts: $this->unitCounts($teacherId),
            unitsByType: $this->unitsByType($teacherId),
            rates: $this->ratesInForce($teacherId),
            pendingRateRequest: $this->pendingRateRequest($teacherId),
            grossMinor: $money['gross'],
            deductions: $money['deductions'],
            netMinor: $money['net'],
            carriedInMinor: $carriedIn,
            currency: $this->currency($open, $lastClosed),
        );
    }

    /**
     * The currency the window is denominated in.
     *
     * A period that already exists declares it; otherwise the last closed one
     * does; otherwise the platform default. Written as three explicit branches
     * rather than a chain of `?->` and `??`, because a nullsafe on the left of a
     * `??` reads as a guard that is not guarding anything — `??` already
     * swallows the access on null all by itself.
     */
    private function currency(?SettlementPeriod $open, ?SettlementPeriod $lastClosed): string
    {
        if ($open !== null) {
            return (string) $open->currency;
        }

        if ($lastClosed !== null) {
            return (string) $lastClosed->currency;
        }

        return $this->settings->currency();
    }

    /** @return array<string, int> */
    private function unitCounts(int $teacherId): array
    {
        $counts = [];

        foreach (TeachingUnitStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        $rows = $this->windowUnits($teacherId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->status->value] = (int) $row->getAttribute('aggregate_count');
        }

        return $counts;
    }

    /**
     * Delivered work split by session type (FR-017).
     *
     * Reversals are excluded: a correction is not a second session, and counting
     * it would report more work than was taught.
     *
     * @return array<string, int>
     */
    private function unitsByType(int $teacherId): array
    {
        $rows = $this->windowUnits($teacherId)
            ->where('status', '!=', TeachingUnitStatus::Reversed->value)
            ->groupBy('session_type')
            ->selectRaw('session_type, COUNT(*) as aggregate_count')
            ->get();

        $byType = [];

        foreach ($rows as $row) {
            $byType[$row->session_type->value] = (int) $row->getAttribute('aggregate_count');
        }

        return $byType;
    }

    private function studentsCount(int $teacherId): int
    {
        return $this->windowUnits($teacherId)
            ->where('status', '!=', TeachingUnitStatus::Reversed->value)
            ->distinct()
            ->count('student_user_id');
    }

    /**
     * The window's money, in one grouped read of the ledger.
     *
     * Grouped by type AND reason so a deduction keeps the sentence explaining it:
     * "we took 200 off" with no reason is the message that generates the support
     * ticket this whole statement exists to avoid. The grouping keeps the row
     * count bounded by distinct reasons rather than by entries, so ten thousand
     * reversals are still one line.
     *
     * @return array{gross: int, deductions: list<array{type: string, type_label: string, reason: string|null, amount_minor: int}>, net: int}
     */
    private function money(int $teacherId, int $carriedIn): array
    {
        $rows = LedgerEntry::query()
            ->where('teacher_profile_id', $teacherId)
            ->whereNull('settlement_period_id')
            ->groupBy('type', 'reason')
            ->selectRaw('type, reason, SUM(amount_minor) as aggregate_amount')
            ->get();

        $gross = 0;
        $deductions = [];

        foreach ($rows as $row) {
            $amount = (int) $row->getAttribute('aggregate_amount');

            // Split on the SIGN, not on the type: the type's sign rule is
            // enforced at write time by WriteLedgerEntry, so the sign is the
            // fact and the type is its label.
            if ($amount >= 0) {
                $gross += $amount;

                continue;
            }

            $reason = $row->getAttribute('reason');

            $deductions[] = [
                'type' => $row->type->value,
                'type_label' => $row->type->label(),
                'reason' => $reason === null ? null : (string) $reason,
                'amount_minor' => $amount,
            ];
        }

        // Carried in, because that is what the teacher is OWED and it is the
        // same arithmetic CloseSettlementPeriod freezes. A net that ignored a
        // negative balance brought forward would show money the next payout is
        // not going to contain.
        $net = array_reduce(
            $deductions,
            static fn (int $carry, array $line): int => $carry + $line['amount_minor'],
            $gross + $carriedIn,
        );

        return ['gross' => $gross, 'deductions' => $deductions, 'net' => $net];
    }

    /**
     * The rates on file today, one per scope.
     *
     * Rates are versioned by insertion, so a scope has as many rows as it has had
     * prices. The newest one that has already taken effect is the one in force —
     * the same rule `RateResolver` applies to a session, asked here about now.
     *
     * @return Collection<int, SettlementRate>
     */
    private function ratesInForce(int $teacherId): Collection
    {
        return SettlementRate::query()
            ->where('teacher_profile_id', $teacherId)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->get()
            ->unique(fn (SettlementRate $rate): string => implode('|', [
                $rate->session_type->value,
                $rate->subject_id ?? '*',
                $rate->grade_level ?? '*',
            ]))
            ->values();
    }

    private function pendingRateRequest(int $teacherId): ?RateChangeRequest
    {
        return RateChangeRequest::query()
            ->where('teacher_profile_id', $teacherId)
            ->where('status', RateRequestStatus::Pending->value)
            ->orderByDesc('requested_at')
            ->first();
    }

    /**
     * Units belonging to the open window: those no close has claimed yet.
     *
     * `settlement_period_id IS NULL` is the definition, not a shortcut — closing
     * is precisely the act of stamping a period onto the rows, which is what
     * stops their total from moving.
     *
     * @return Builder<TeachingUnit>
     */
    private function windowUnits(int $teacherId): Builder
    {
        return TeachingUnit::query()
            ->where('teacher_profile_id', $teacherId)
            ->whereNull('settlement_period_id');
    }
}
