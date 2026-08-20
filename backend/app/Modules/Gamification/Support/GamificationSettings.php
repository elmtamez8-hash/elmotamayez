<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The one place this phase's numbers come from.
 *
 * Every value is a `platform_settings` row an operator edits from the panel,
 * falling back to config/gamification.php — the same shape as SessionSettings and
 * MediaLimits. No Action reaches for config() directly: a limit that can only
 * change by shipping code is a limit nobody ever tunes.
 */
class GamificationSettings
{
    /** How many levels share one competitive band (FR-023 · SC-009). */
    public function levelBandWidth(): int
    {
        return max(1, (int) PlatformSettings::get('gamification.level_band_width', config('gamification.level_band_width', 5)));
    }

    /** The most rows one leaderboard read may return. */
    public function leaderboardWindow(): int
    {
        return max(1, (int) PlatformSettings::get('gamification.leaderboard_window', config('gamification.leaderboard_window', 50)));
    }

    /** How long a finished period is kept before the sweep (FR-026). */
    public function leaderboardRetentionDays(): int
    {
        return (int) PlatformSettings::get('gamification.leaderboard_retention_days', config('gamification.leaderboard_retention_days', 180));
    }

    /**
     * The ceiling on a reward's monthly cap.
     *
     * ⚠️ A PLATFORM NUMBER, not the teacher's. The cap itself is the teacher's
     * choice; this bound is what stops the choice from being unlimited — a limit
     * a teacher sets for themselves is not a control (FR-031).
     */
    public function maxMonthlyCap(): int
    {
        return (int) PlatformSettings::get('gamification.max_monthly_cap', config('gamification.max_monthly_cap', 200));
    }

    public function focusMinMinutes(): int
    {
        return (int) PlatformSettings::get('gamification.focus_min_minutes', config('gamification.focus_min_minutes', 5));
    }

    /** Unbounded, `100000` mutes every optional notification for ever. */
    public function focusMaxMinutes(): int
    {
        return (int) PlatformSettings::get('gamification.focus_max_minutes', config('gamification.focus_max_minutes', 180));
    }
}
