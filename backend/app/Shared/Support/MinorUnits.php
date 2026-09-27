<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * المبلغُ بالوحدةِ الكبرى على الشاشة، وبالصغرى في قاعدةِ البيانات.
 *
 * ⛔ قرارُ المالك (٢٠٢٦-٠٩-٢٧): كلُّ مبلغٍ في اللوحةِ يُكتَبُ ويُعرَضُ بالوحدةِ
 * الكبرى — ‏49.99 لا 4999 — ويُخزَّنُ عدداً صحيحاً بالوحدةِ الصغرى كما كان.
 * قبلَه كانَ حقلُ سعرِ الباقةِ رقميّاً حرّاً يُقَصُّ بـ`(int)`، فمَن كتبَ 49.99
 * ظانّاً أنّه يكتبُ ريالاً خزَّنَ 49 هللة.
 *
 * ⚠️ التحويلُ نصّيٌّ، لا `* 100`: ‏`(int) (19.99 * 100)` تساوي 1998 في
 * الفاصلةِ العائمة. نفصلُ عندَ النقطةِ ونُكمِلُ الكسرَ إلى خانتَين.
 *
 * كلُّ العملاتِ المدعومةِ اليومَ بخانتَين عشريّتَين، كما يقولُ
 * `Settlement\Support\Money` صراحة.
 */
final class MinorUnits
{
    private const DECIMALS = 2;

    /**
     * «49.99» ⇐ 4999. الفراغُ ⇐ null. ما ليسَ رقماً بخانتَين على الأكثر ⇐ null
     * (التحقّقُ في الحقلِ يرفضُه قبلَ أن يصلَ إلى هنا).
     */
    public static function fromMajor(mixed $major): ?int
    {
        if ($major === null || $major === '') {
            return null;
        }

        if (is_int($major)) {
            return $major * 10 ** self::DECIMALS;
        }

        if (is_float($major)) {
            $major = number_format($major, self::DECIMALS, '.', '');
        }

        if (! is_string($major)) {
            return null;
        }

        $major = trim($major);

        if (preg_match('/^(-?)(\d*)(?:\.(\d{0,'.self::DECIMALS.'}))?$/', $major, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }

        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = str_pad($m[3] ?? '', self::DECIMALS, '0');
        $minor = (int) ($whole.$fraction);

        return $m[1] === '-' ? -$minor : $minor;
    }

    /** 4999 ⇐ «49.99». ‏null ⇐ null. */
    public static function toMajor(?int $minor): ?string
    {
        if ($minor === null) {
            return null;
        }

        $scale = 10 ** self::DECIMALS;
        $absolute = abs($minor);

        return sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv($absolute, $scale), $absolute % $scale);
    }
}
