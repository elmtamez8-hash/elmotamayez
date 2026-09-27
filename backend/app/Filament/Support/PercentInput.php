<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * نسبةٌ مخزَّنةٌ كسراً (٠٫٥) تُكتَبُ وتُعرَضُ نسبةً مئويّة (50 ٪).
 *
 * ⚠️ «‏0.5 تعني النصف» كانَ نصَّ المساعدةِ تحتَ حقلٍ يقبلُ 0.5 و50 معاً شكلاً،
 * ويرفضُ الثانيةَ بحدٍّ أعلى اسمُه ١ — فالمشغِّلُ يقرأُ «نسبة» ويكتبُ 50. الآن
 * يكتبُ 50 ويُخزَّنُ 0.5، والقرّاءُ كلُّهم على الكسرِ كما كانوا.
 *
 * التحويلُ في الحقلِ نفسِه (ملءٌ وحفظ)، فالصفحةُ تستلمُ الكسرَ في `$data`.
 * وخانتان عشريّتان مقبولتان حتّى يرجعَ كسرٌ قديمٌ مثلُ ٠٫٣٣٣ دونَ أن يُرفَض.
 */
final class PercentInput
{
    public static function make(string $name, float $minPercent = 0, float $maxPercent = 100): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->minValue($minPercent)
            ->maxValue($maxPercent)
            ->step(1)
            ->rule('decimal:0,2')
            ->suffix('٪')
            ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? self::toPercent((float) $state) : $state)
            ->dehydrateStateUsing(fn (mixed $state): ?float => is_numeric($state) ? self::toRatio((float) $state) : null);
    }

    public static function toPercent(float $ratio): float
    {
        return round($ratio * 100, 2);
    }

    public static function toRatio(float $percent): float
    {
        return round($percent / 100, 4);
    }
}
