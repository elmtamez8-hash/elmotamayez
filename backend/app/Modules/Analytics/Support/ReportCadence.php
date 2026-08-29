<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use Carbon\CarbonImmutable;

/** How often a scheduled report goes out (spec 011 · FR-045). */
enum ReportCadence: string
{
    case Weekly = 'weekly';

    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'أسبوعيّاً',
            self::Monthly => 'شهريّاً',
        };
    }

    /**
     * Whether a subscription last sent on `$lastSentOn` is due today.
     *
     * ⚠️ IN PHP, NOT IN SQL. `DATE_ADD` on MySQL against `date()` on SQLite is two
     * dialects for one predicate — the reason `BanReader` judges an expiry here
     * too. The set this runs over is tiny by construction: one row per person who
     * asked for a report.
     */
    public function isDue(?CarbonImmutable $lastSentOn, CarbonImmutable $today): bool
    {
        if ($lastSentOn === null) {
            return true;
        }

        $days = $this === self::Weekly ? 7 : 28;

        return $lastSentOn->addDays($days)->lessThanOrEqualTo($today);
    }
}
