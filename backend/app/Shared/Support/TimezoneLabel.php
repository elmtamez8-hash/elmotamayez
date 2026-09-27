<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Arabic for an IANA zone name — the server's copy of the frontend's map, for
 * the times printed in notifications, where no browser is there to label them.
 *
 * ⛔ ONE NAMING, THE «البلد — المدينة» FORM (owner decision 2026-09-27). This
 * map used to say «توقيت قطر» while the settings picker said «قطر — الدوحة» —
 * two names for one clock.
 *
 * ⛔ THE DATA IS `timezone-names.json` BESIDE THIS CLASS, AND IT IS A COPY of
 * `frontend/src/lib/timezone-names.json`. The two apps build from separate
 * Docker contexts, so neither can read the other's file at runtime; edit one,
 * copy it over the other. `TimezoneLabelParityTest` fails the build the day the
 * two disagree, and the day a zone PHP accepts has no name.
 *
 * Since 2026-09-27 the map names every zone — PHP's `timezone_identifiers_list()`
 * and the older spellings browsers still report (`Asia/Calcutta`, `Europe/Kiev`).
 *
 * ⚠️ The label is a PLACE, not a phrase: a sentence that means «on that clock»
 * writes «توقيت» itself — `UserClock::format()` prints «(توقيت قطر — الدوحة)».
 *
 * ⚠️ THE FALLBACK IS THE NAME ITSELF. A zone the map does not know (one a
 * future tzdata adds) is still correct; a blank would hide which clock the
 * number is on, which is the whole reason the label is printed.
 */
final class TimezoneLabel
{
    /** @var array<string, string>|null */
    private static ?array $labels = null;

    public static function for(string $zone): string
    {
        return self::labels()[$zone] ?? $zone;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        if (self::$labels === null) {
            $decoded = json_decode((string) file_get_contents(__DIR__.'/timezone-names.json'), true, flags: JSON_THROW_ON_ERROR);
            $labels = [];

            foreach (is_array($decoded) ? $decoded : [] as $zone => $label) {
                if (is_string($zone) && is_string($label)) {
                    $labels[$zone] = $label;
                }
            }

            self::$labels = $labels;
        }

        return self::$labels;
    }
}
