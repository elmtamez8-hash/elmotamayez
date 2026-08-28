<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * قائمةُ الخياراتِ التي تقرؤها كلُّ قائمةٍ منسدلةٍ وكلُّ مرشِّحٍ وكلُّ شارة.
 *
 * مشتقّةٌ من `cases()`، فحالةٌ تُضافُ إلى المجموعةِ تظهرُ في كلِّ شاشةٍ تعرضُها
 * دونَ أن يمرَّ أحدٌ على الشاشات. {@see HasArabicLabel}
 *
 * @phpstan-require-implements HasArabicLabel
 */
trait BuildsOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (static::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }

    /**
     * العربيّةُ المقابلةُ لقيمةٍ خامٍ آتيةٍ من عمودٍ نصّيٍّ غيرِ مُحوَّل.
     *
     * ⚠️ تُرجِعُ القيمةَ نفسَها إن لم تُعرَف. صفٌّ قديمٌ بقيمةٍ خارجَ المجموعةِ
     * يُقرأُ كما هو بدلَ أن يختفيَ من الجدولِ أو يُسقِطَ الصفحةَ بـ`ValueError`.
     */
    public static function labelFor(int|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return static::tryFrom($value)?->label() ?? (string) $value;
    }
}
