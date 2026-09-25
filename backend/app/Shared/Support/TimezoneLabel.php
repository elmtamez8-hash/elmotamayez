<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Arabic for an IANA zone name — the server's copy of the frontend's
 * `timezoneLabel()` (`frontend/src/lib/labels.ts`), for the times printed in
 * notifications, where no browser is there to label them.
 *
 * ⚠️ THE FALLBACK IS THE NAME ITSELF. A zone nobody translated is still correct;
 * a blank would hide which clock the number is on, which is the whole reason
 * the label is printed.
 */
final class TimezoneLabel
{
    private const LABELS = [
        'Asia/Qatar' => 'توقيت قطر',
        'Asia/Riyadh' => 'توقيت السعودية',
        'Asia/Dubai' => 'توقيت الإمارات',
        'Asia/Kuwait' => 'توقيت الكويت',
        'Asia/Bahrain' => 'توقيت البحرين',
        'Asia/Muscat' => 'توقيت عُمان',
        'Africa/Cairo' => 'توقيت مصر',
        'UTC' => 'التوقيت العالمي',
    ];

    public static function for(string $zone): string
    {
        return self::LABELS[$zone] ?? $zone;
    }
}
