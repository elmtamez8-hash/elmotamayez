<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Arabic for an IANA zone name — the server's copy of the frontend's
 * `ZONE_PLACES` (`frontend/src/lib/timezone-names.ts`), for the times printed
 * in notifications, where no browser is there to label them.
 *
 * ⛔ ONE NAMING, THE «البلد — المدينة» FORM (owner decision 2026-09-27). This
 * map used to say «توقيت قطر» while the settings picker said «قطر — الدوحة» —
 * two names for one clock. The entries here are a copy of the frontend's, and
 * `TimezoneLabelParityTest` fails the build the day the two disagree.
 *
 * ⚠️ The label is a PLACE, not a phrase: a sentence that means «on that clock»
 * writes «توقيت» itself — `UserClock::format()` prints «(توقيت قطر — الدوحة)».
 *
 * ⚠️ THE FALLBACK IS THE NAME ITSELF. A zone nobody translated is still correct;
 * a blank would hide which clock the number is on, which is the whole reason
 * the label is printed.
 */
final class TimezoneLabel
{
    /** @var array<string, string> */
    public const LABELS = [
        'Asia/Qatar' => 'قطر — الدوحة',
        'Africa/Cairo' => 'مصر — القاهرة',
        'Asia/Riyadh' => 'السعودية — الرياض',
        'Asia/Dubai' => 'الإمارات — دبي',
        'Asia/Kuwait' => 'الكويت — الكويت',
        'Asia/Bahrain' => 'البحرين — المنامة',
        'Asia/Muscat' => 'عُمان — مسقط',
        'Asia/Baghdad' => 'العراق — بغداد',
        'Asia/Amman' => 'الأردن — عمّان',
        'Asia/Damascus' => 'سوريا — دمشق',
        'Asia/Beirut' => 'لبنان — بيروت',
        'Asia/Gaza' => 'فلسطين — غزة',
        'Asia/Hebron' => 'فلسطين — الخليل',
        'Asia/Aden' => 'اليمن — عدن',
        'Africa/Khartoum' => 'السودان — الخرطوم',
        'Africa/Tripoli' => 'ليبيا — طرابلس',
        'Africa/Tunis' => 'تونس — تونس',
        'Africa/Algiers' => 'الجزائر — الجزائر',
        'Africa/Casablanca' => 'المغرب — الدار البيضاء',
        'Africa/Nouakchott' => 'موريتانيا — نواكشوط',
        'Africa/Mogadishu' => 'الصومال — مقديشو',
        'Africa/Djibouti' => 'جيبوتي — جيبوتي',
        'Indian/Comoro' => 'جزر القمر — موروني',
        'Europe/Istanbul' => 'تركيا — إسطنبول',
        'Europe/London' => 'المملكة المتحدة — لندن',
        'Europe/Dublin' => 'أيرلندا — دبلن',
        'Europe/Paris' => 'فرنسا — باريس',
        'Europe/Berlin' => 'ألمانيا — برلين',
        'Europe/Amsterdam' => 'هولندا — أمستردام',
        'Europe/Brussels' => 'بلجيكا — بروكسل',
        'Europe/Madrid' => 'إسبانيا — مدريد',
        'Europe/Rome' => 'إيطاليا — روما',
        'Europe/Vienna' => 'النمسا — فيينا',
        'Europe/Stockholm' => 'السويد — ستوكهولم',
        'Europe/Oslo' => 'النرويج — أوسلو',
        'Europe/Copenhagen' => 'الدنمارك — كوبنهاغن',
        'Europe/Athens' => 'اليونان — أثينا',
        'Europe/Moscow' => 'روسيا — موسكو',
        'Asia/Tehran' => 'إيران — طهران',
        'Asia/Karachi' => 'باكستان — كراتشي',
        'Asia/Kolkata' => 'الهند — كولكاتا',
        'Asia/Dhaka' => 'بنغلاديش — دكا',
        'Asia/Kuala_Lumpur' => 'ماليزيا — كوالالمبور',
        'Asia/Jakarta' => 'إندونيسيا — جاكرتا',
        'Asia/Singapore' => 'سنغافورة — سنغافورة',
        'Asia/Shanghai' => 'الصين — شنغهاي',
        'Asia/Tokyo' => 'اليابان — طوكيو',
        'Australia/Sydney' => 'أستراليا — سيدني',
        'Africa/Lagos' => 'نيجيريا — لاغوس',
        'Africa/Nairobi' => 'كينيا — نيروبي',
        'Africa/Johannesburg' => 'جنوب أفريقيا — جوهانسبرغ',
        'America/New_York' => 'الولايات المتحدة — نيويورك',
        'America/Chicago' => 'الولايات المتحدة — شيكاغو',
        'America/Denver' => 'الولايات المتحدة — دنفر',
        'America/Los_Angeles' => 'الولايات المتحدة — لوس أنجلوس',
        'America/Toronto' => 'كندا — تورونتو',
        'America/Sao_Paulo' => 'البرازيل — ساو باولو',
        'UTC' => 'التوقيت العالمي (UTC)',
    ];

    public static function for(string $zone): string
    {
        return self::LABELS[$zone] ?? $zone;
    }
}
