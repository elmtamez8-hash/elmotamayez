<?php

declare(strict_types=1);

namespace App\Shared\Support;

use DateTimeZone;
use Locale;

/**
 * الدولُ بأسمائها العربيّة — لشاشاتِ اللوحةِ وعدّاداتِها.
 *
 * ⛔ الدولُ العشرُ الأولى هي قائمةُ التسجيلِ نفسُها
 * (`frontend/src/lib/countries.ts`)، بالترتيبِ والأسماءِ حرفاً، و
 * `CountriesParityTest` يقارنُ النسختَين: كلُّ تطبيقٍ يُبنى وحدَه ولا يرى ملفَّ
 * الآخر — عائلةُ `TimezoneLabel` نفسُها.
 *
 * ⚠️ والقائمةُ الكاملةُ بعدَها: مسارُ التسجيلِ يقبلُ أيَّ رمزٍ من حرفَين
 * (`RegisterStudentRequest`)، فحسابٌ من دولةٍ خارجَ العشرِ موجودٌ فعلاً، ومنتقٍ
 * بالعشرِ وحدَها يُفرِغُ دولتَه عندَ أوّلِ تعديل. الأسماءُ الباقيةُ من ICU
 * (`ext-intl`، وهو شرطٌ لـFilament نفسِه)، والرموزُ من قاعدةِ المناطقِ الزمنيّة.
 */
final class Countries
{
    /** @var array<string, string> The signup list, in its order — mirrored by the frontend. */
    public const SIGNUP = [
        'QA' => 'قطر',
        'SA' => 'السعودية',
        'AE' => 'الإمارات',
        'KW' => 'الكويت',
        'BH' => 'البحرين',
        'OM' => 'عُمان',
        'EG' => 'مصر',
        'JO' => 'الأردن',
        'LB' => 'لبنان',
        'MA' => 'المغرب',
    ];

    /** @var array<string, string>|null */
    private static ?array $all = null;

    /** «QA» ⇐ «قطر». رمزٌ لا يُعرَفُ يُعرَضُ كما هو، والفراغُ ⇐ null. */
    public static function name(mixed $code): ?string
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        $code = strtoupper($code);

        return self::all()[$code] ?? $code;
    }

    /**
     * Every country, code => Arabic name: the signup ten first, then the rest
     * alphabetically by their Arabic name.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $rest = [];

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $code = (new DateTimeZone($zone))->getLocation()['country_code'] ?? '??';

            if (strlen($code) !== 2 || $code === '??' || isset(self::SIGNUP[$code])) {
                continue;
            }

            $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, 'ar') : '';
            $rest[$code] = is_string($name) && $name !== '' && $name !== $code ? $name : $code;
        }

        asort($rest, SORT_STRING);

        return self::$all = self::SIGNUP + $rest;
    }

    /**
     * Picker options — plus the stored code when it is not a country we know,
     * so an edit neither blanks it nor refuses a save that never touched it.
     *
     * @return array<string, string>
     */
    public static function options(mixed $current = null): array
    {
        $options = self::all();

        if (is_string($current) && $current !== '' && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }
}
