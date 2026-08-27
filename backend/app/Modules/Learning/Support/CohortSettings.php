<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The two operational numbers spec 021 has, read from `platform_settings` and
 * falling back to `config/cohorts.php`.
 *
 * ⚠️ NOT ONE CONSTANT IN THE CODE, per the rule written in `config/media.php`:
 * an operational number is a row an operator edits from the panel, and one that
 * can only change by shipping a release is one nobody ever tunes.
 *
 * ⚠️ NEITHER IS READ BY ANYTHING YET — the cohort routes arrive with US3. It is
 * here in the Setup phase so that the first Action to need a default reaches for
 * a settings row instead of writing `20` inline, which is how the number stops
 * being tunable before anybody notices it was meant to be.
 */
final class CohortSettings
{
    /**
     * The capacity a newly created group is given when the teacher names none.
     *
     * ⚠️ A DEFAULT, NOT A CEILING, and the distinction is what the API sends: a
     * group with no declared capacity answers `seats_left: null`, never a number.
     * "Unlimited" and "twenty free" are different promises to a student choosing
     * between two groups.
     */
    public static function defaultCapacity(): int
    {
        return (int) PlatformSettings::get('cohorts.default_capacity', 20);
    }

    /**
     * How long a temporary write ban lasts when the teacher gives no duration
     * (FR-045).
     *
     * The open-ended ban stays a separate, deliberate decision — a duration
     * defaulted to "for ever" would turn one moment in one lesson into a silence
     * nobody remembers to lift.
     */
    public static function defaultChatBanMinutes(): int
    {
        return (int) PlatformSettings::get('cohorts.default_chat_ban_minutes', 20);
    }
}
