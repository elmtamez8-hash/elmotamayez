<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Traits\CanonicalisesTimezoneInput;

/**
 * The one spelling of a zone this server stores: the IANA name PHP lists.
 *
 * ⛔ BROWSERS REPORT OLD SPELLINGS, AND LARAVEL'S `timezone` RULE REFUSED THEM
 * (measured 2026-09-27). Chrome and Node still say `Asia/Calcutta`,
 * `Europe/Kiev`, `America/Buenos_Aires` — 19 names in all — where PHP's
 * `timezone_identifiers_list()` has `Asia/Kolkata`, `Europe/Kyiv`,
 * `America/Argentina/Buenos_Aires`. A reader in India picked «الهند — كولكاتا»
 * and got a 422, and their sign-in stamp was refused in silence. Owner decision
 * 2026-09-27: ACCEPT the old spelling, STORE the new one — normalised where it
 * is written ({@see CanonicalisesTimezoneInput} on each
 * request, and again in each Action, which seeders and other callers share).
 *
 * The map is `timezone-aliases.json` beside this class, derived from ICU's
 * `IntlTimeZone::getIanaID()` over exactly the names the browser lists and PHP
 * does not, and an identical copy of `frontend/src/lib/timezone-aliases.json`
 * (the picker folds the same names). `TimezoneLabelParityTest` holds the two
 * copies together and checks every entry against ICU.
 *
 * ⚠️ A static map, not a runtime call to ICU: `getIanaID()` needs ICU 74+, and
 * a production image on an older ICU would answer differently from the tests.
 */
final class TimezoneName
{
    /** @var array<string, string>|null */
    private static ?array $aliases = null;

    /** The stored spelling for `$zone` — itself when it is not an old one. */
    public static function canonical(string $zone): string
    {
        return self::aliases()[$zone] ?? $zone;
    }

    /** @return array<string, string> old spelling → the one PHP lists */
    public static function aliases(): array
    {
        if (self::$aliases === null) {
            $decoded = json_decode((string) file_get_contents(__DIR__.'/timezone-aliases.json'), true, flags: JSON_THROW_ON_ERROR);
            $aliases = [];

            foreach (is_array($decoded) ? $decoded : [] as $alias => $zone) {
                if (is_string($alias) && is_string($zone)) {
                    $aliases[$alias] = $zone;
                }
            }

            self::$aliases = $aliases;
        }

        return self::$aliases;
    }
}
