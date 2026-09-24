<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\LiveSessions\Support\SessionSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Which DAY a subscription starts and ends on — counted in the platform's
 * timezone, stored as UTC instants.
 *
 * ⛔ `CarbonImmutable::today()` IS UTC, AND QATAR IS THREE HOURS AHEAD OF IT. A
 * subscription approved at 01:30 on the 25th in Doha was dated from the 24th —
 * a paid day that had already ended before the student could use it — and its
 * last day closed at 03:00 the NEXT morning local time, so the seat window and
 * the expiry sweep both ran three hours off the calendar the student reads.
 *
 * ⚠️ THE TIMEZONE IS `sessions.timezone`, READ THROUGH `SessionSettings`, THE
 * SAME ROW `GamificationCalendar` READS. A third declaration of the platform's
 * timezone is how one clock drifts from another in silence.
 *
 * ⚠️ AND EVERY INSTANT LEAVES HERE IN UTC. Laravel formats a Carbon binding with
 * the grammar's date format and never converts its zone, so a Doha-zoned
 * instant written to a timestamp column is stored as if it were UTC — three
 * hours wrong in the other direction. Dates leave as `Y-m-d` strings, which is
 * what a DATE column compares against.
 */
final class SubscriptionDays
{
    public function __construct(private readonly SessionSettings $settings) {}

    public function timezone(): string
    {
        return $this->settings->timezone();
    }

    /** The platform's calendar date at `$at` (default: now), as a UTC-midnight date value. */
    public function today(?DateTimeInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dateOf($at ?? CarbonImmutable::now()));
    }

    /** `Y-m-d` of the platform-local day an instant falls on. */
    public function dateOf(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone($this->timezone())->toDateString();
    }

    /** The last instant of a platform-local day, in UTC. */
    public function endOf(DateTimeInterface|string $date): CarbonImmutable
    {
        return $this->localStartOf($date)->addDay()->utc()->subSecond();
    }

    /** The first instant of the platform-local day AFTER `$date`, in UTC — the `<` bound. */
    public function startOfDayAfter(DateTimeInterface|string $date): CarbonImmutable
    {
        // The day is added in LOCAL time and converted afterwards, so a zone
        // with a daylight-saving shift still ends its day at local midnight.
        return $this->localStartOf($date)->addDay()->utc();
    }

    private function localStartOf(DateTimeInterface|string $date): CarbonImmutable
    {
        $day = is_string($date) ? substr($date, 0, 10) : CarbonImmutable::instance($date)->toDateString();

        return CarbonImmutable::parse($day, $this->timezone())->startOfDay();
    }
}
