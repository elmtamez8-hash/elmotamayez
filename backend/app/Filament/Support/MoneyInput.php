<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Modules\Payments\Enums\Currency;
use App\Shared\Support\MinorUnits;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * حقلُ مبلغٍ في اللوحة: يُكتَبُ بالوحدةِ الكبرى ويُخزَّنُ بالصغرى.
 *
 * ⛔ قرارُ المالك (٢٠٢٦-٠٩-٢٧): المشغِّلُ يكتبُ 49.99 ويرى اسمَ العملةِ بجانبِه،
 * والعمودُ يبقى عدداً صحيحاً بالوحدةِ الصغرى. التحويلُ في الحقلِ نفسِه —
 * `formatStateUsing` عندَ الملء و`dehydrateStateUsing` عندَ الحفظ — فكلُّ صفحةٍ
 * تقرأُ `$data[...]` تستلمُ عدداً صحيحاً بالصغرى كما كانت، ولا يعرفُ فعلٌ ولا
 * نموذجٌ أنّ الشاشةَ تغيّرت.
 *
 * ⚠️ لا `integer()` هنا أبداً: هو ما جعلَ الحقلَ القديمَ يطلبُ 4999، وكسرٌ
 * بثلاثِ خاناتٍ يُرفَضُ بقاعدةِ `decimal:0,2` لا يُقَصُّ بصمت.
 */
final class MoneyInput
{
    /**
     * @param  Closure|string|null  $currency  رمزُ العملة (QAR…)، أو دالّةٌ تُقيَّمُ
     *                                         بحقنِ Filament (`Get`، `$record`) وتُعيدُه.
     */
    public static function make(string $name, Closure|string|null $currency, int $maxMinor = 100_000_000): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->maxValue(intdiv($maxMinor, 100))
            ->step(0.01)
            ->rule('decimal:0,2')
            ->suffix(fn (TextInput $component): string => self::currencyLabel($component->evaluate($currency)))
            ->formatStateUsing(fn (mixed $state): ?string => self::hydrate($state))
            ->dehydrateStateUsing(fn (mixed $state): ?int => MinorUnits::fromMajor($state));
    }

    /**
     * What the stored minor amount looks like in the box.
     *
     * ⚠️ `numeric()` casts the state to a FLOAT before this runs — 30000 arrives
     * as 30000.0 — so a check for `int` alone read every stored price as «not a
     * number» and opened the form on an empty box, which a save then wrote back
     * as null. Hydration only ever sees the stored value, so any number here is
     * minor units.
     */
    public static function hydrate(mixed $state): ?string
    {
        return match (true) {
            is_int($state), is_float($state) => MinorUnits::toMajor((int) round($state)),
            is_string($state) && ctype_digit($state) => MinorUnits::toMajor((int) $state),
            is_string($state) => $state,
            default => null,
        };
    }

    /** الرمزُ العربيُّ القصير (ر.ق) — ورمزُ عملةٍ لا نعرفُها كما هو. */
    public static function currencyLabel(mixed $currency): string
    {
        if (! is_string($currency) || $currency === '') {
            return '';
        }

        return Currency::tryFrom($currency)?->short() ?? $currency;
    }
}
