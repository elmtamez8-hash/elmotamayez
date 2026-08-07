<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use Carbon\CarbonImmutable;

/**
 * Which days the teacher's next unclosed window covers.
 *
 * Shared between the statement and the closing job on purpose. Two derivations
 * of "the current window" that disagree by a day is not a display bug: the
 * statement would show a total over one set of days and the close would freeze
 * a total over another, and the teacher would be paid the second while reading
 * the first.
 */
class SettlementWindow
{
    public function __construct(
        private readonly SettlementSettings $settings,
    ) {}

    /**
     * The last period this teacher closed, if any. Its carry-out opens the next.
     *
     * `$excluding` exists for the close itself: by the time totals are computed
     * the period being closed is already `closed`, so without it a period would
     * read its own carry-out as its opening balance.
     */
    public function lastClosed(int $teacherProfileId, ?int $excluding = null): ?SettlementPeriod
    {
        return SettlementPeriod::query()
            ->where('teacher_profile_id', $teacherProfileId)
            ->whereIn('status', [SettlementPeriodStatus::Closed->value, SettlementPeriodStatus::Paid->value])
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding))
            ->orderByDesc('ends_on')
            ->first();
    }

    public function open(int $teacherProfileId): ?SettlementPeriod
    {
        return SettlementPeriod::query()
            ->where('teacher_profile_id', $teacherProfileId)
            ->where('status', SettlementPeriodStatus::Open->value)
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * The bounds of the window that has not been closed yet.
     *
     * An existing open row declares its own; otherwise the window starts the day
     * after the last close, or — for a teacher who has never closed one — on the
     * day of their earliest unsettled unit. Deliberately not "the last 30 days":
     * that would print a start date AFTER work the same statement is counting,
     * because the aggregation is by `settlement_period_id IS NULL`, not by date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(int $teacherProfileId, ?SettlementPeriod $open, ?SettlementPeriod $lastClosed): array
    {
        if ($open !== null) {
            return [
                CarbonImmutable::parse($open->starts_on->toDateString()),
                CarbonImmutable::parse($open->ends_on->toDateString()),
            ];
        }

        if ($lastClosed !== null) {
            $startsOn = CarbonImmutable::parse($lastClosed->ends_on->toDateString())->addDay();
        } else {
            $earliest = TeachingUnit::query()
                ->where('teacher_profile_id', $teacherProfileId)
                ->whereNull('settlement_period_id')
                ->min('delivered_at');

            $startsOn = $earliest === null
                ? CarbonImmutable::now()->startOfDay()
                : CarbonImmutable::parse((string) $earliest)->startOfDay();
        }

        return [$startsOn, $startsOn->addDays($this->settings->periodDays() - 1)];
    }
}
