<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Modules\LiveSessions\Support\SessionSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * The ONE place a day or a week begins (FR-019).
 *
 * The daily cap, the streak and the leaderboard period key all read this class.
 * Letting each compute its own boundary is how a student finds yesterday's cap
 * still in force after midnight while the board has already rolled over.
 *
 * ⚠️ IT READS THE PLATFORM ZONE THROUGH `SessionSettings::timezone()`, NOT A NEW
 * KEY — and since 2026-09-25 that is `SESSIONS_TIMEZONE` and nothing else. Until
 * then it read a `platform_settings` row the panel could edit while the
 * scheduler and `notifications.default_timezone` read the environment, so this
 * comment's «one source» was two until somebody saved the panel. The row is
 * gone, the panel field is read-only, and moving the platform's day means
 * changing the environment and restarting — which moves the daily cap, the
 * billing day and the nightly schedules together.
 *
 * ⚠️ THE PLATFORM DAY IS NOT THE VIEWER'S CLOCK. A student in Cairo SEES their
 * lessons on their own clock (`SessionSettings::timezoneFor()`), but the cap,
 * the streak and the week roll over at one instant for everybody.
 *
 * ⚠️ AND `config('app.timezone')` STAYS `UTC`. Stored timestamps are not touched.
 * What is converted is the BOUNDARY, here and nowhere else.
 */
class GamificationCalendar
{
    public function __construct(private readonly SessionSettings $settings) {}

    public function timezone(): string
    {
        return $this->settings->timezone();
    }

    /** The local calendar day a moment falls in, `Y-m-d`. */
    public function dayKey(?DateTimeInterface $at = null): string
    {
        return $this->local($at)->format('Y-m-d');
    }

    /**
     * The half-open UTC window `[start, end)` of one local day.
     *
     * ⚠️ A PAIR OF UTC TIMESTAMPS, NOT A STRING TO COMPARE AGAINST A COLUMN, and
     * both of the attractive alternatives are broken:
     *
     * - `whereDate(created_at, ...)` wraps the column in a function, which throws
     *   away the index it was given — the lesson `FreezePeriod::covering()` cost
     *   spec 005.
     * - `CONVERT_TZ()` returns NULL on any MySQL where the timezone tables have
     *   not been loaded, which is the common case on managed MySQL. The predicate
     *   then matches nothing, THE CAP IS NEVER ENFORCED, and nothing errors.
     *   SQLite has no CONVERT_TZ at all, so no local test could ever see it.
     *
     * Half-open because `created_at` is a timestamp: `<= end of day` binds
     * midnight and silently drops everything written in the last second.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayBounds(?string $dayKey = null): array
    {
        $start = CarbonImmutable::parse($dayKey ?? $this->dayKey(), $this->timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * The leaderboard key of the week a moment falls in — weeks start SUNDAY (Q5).
     *
     * Sunday, not ISO Monday: the cohort a student is compared against is their
     * class, and the two days of their weekend have to land in one week.
     *
     * ⚠️ `format('W')` IS BANNED HERE. ISO weeks begin on Monday, so every Sunday
     * would carry a different number from the six days that follow it and one week
     * would split into two keys — a leaderboard that resets on Sunday evening for
     * no visible reason. The ordinal is derived from the date of the week's own
     * Sunday, which is unique by construction.
     */
    public function weekKey(?DateTimeInterface $at = null): string
    {
        $sunday = $this->weekStart($at);

        return sprintf('w:%d-W%02d', $sunday->year, intdiv($sunday->dayOfYear - 1, 7) + 1);
    }

    /** The local Sunday that opens the week a moment falls in. */
    public function weekStart(?DateTimeInterface $at = null): CarbonImmutable
    {
        // Passed explicitly rather than set globally: a global first-day-of-week
        // would silently change every other date calculation in the product.
        return $this->local($at)->startOfWeek(CarbonInterface::SUNDAY);
    }

    /** The half-open UTC window of the week a moment falls in.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function weekBounds(?DateTimeInterface $at = null): array
    {
        $start = $this->weekStart($at);

        return [$start->utc(), $start->addWeek()->utc()];
    }

    /**
     * The hall-of-fame key (FR-022).
     *
     * Calendar thirds. The academic term is a product decision nobody has taken
     * yet, and this key is read by ONE screen that is explicitly separate from the
     * weekly ranking — so a stable, obvious division beats an invented calendar
     * that would then have to be unwound. Changing it later re-keys the hall of
     * fame and nothing else, because the ledger is the source and the boards
     * rebuild from it.
     */
    public function termKey(?DateTimeInterface $at = null): string
    {
        $local = $this->local($at);

        return sprintf('t:%d-%d', $local->year, intdiv($local->month - 1, 4) + 1);
    }

    private function local(?DateTimeInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone($this->timezone());
    }
}
