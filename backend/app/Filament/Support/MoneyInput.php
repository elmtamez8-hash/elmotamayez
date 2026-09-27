<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Modules\Payments\Enums\Currency;
use App\Shared\Support\MinorUnits;
use Closure;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

/**
 * حقلُ مبلغٍ في اللوحة، بالوحدةِ الكبرى — وهو عرضٌ فقط، لا تحويلَ فيه.
 *
 * ⛔ قرارُ المالك (٢٠٢٦-٠٩-٢٧): المشغِّلُ يكتبُ 49.99 ويرى اسمَ العملةِ بجانبِه،
 * والعمودُ يبقى عدداً صحيحاً بالوحدةِ الصغرى — و**النموذجُ** هو الذي يحوِّل،
 * لا الاستمارة. فالحقلُ يُربَطُ بالخاصّيّةِ الافتراضيّة (`price` لا `price_minor`)
 * التي يبنيها {@see MinorUnits::attribute()}: القراءةُ منها تُعطي «49.99»،
 * والكتابةُ فيها تُخزِّنُ 4999.
 *
 * ⚠️ لماذا يقرأُ الحقلُ من السجلّ عندَ الملء: صفحةُ التعديلِ في Filament تملأُ
 * الاستمارةَ من `attributesToArray()`، وهي لا تحملُ خاصّيّةً افتراضيّةً غيرَ
 * مُلحَقة — فالحقلُ كانَ سيُفتَحُ فارغاً على سعرٍ موجود، ويحفظُه المشغِّلُ فارغاً.
 * و`$appends` ليس الحلّ: يغيّرُ كلَّ JSON يُسلسِلُ النموذج. فالحقلُ يسألُ السجلَّ
 * عن خاصّيّتِه بالاسمِ نفسِه — قراءةٌ، لا تحويل.
 *
 * ⚠️ لا `integer()` هنا أبداً: هو ما جعلَ الحقلَ القديمَ يطلبُ 4999، وكسرٌ
 * بثلاثِ خاناتٍ يُرفَضُ بقاعدةِ `decimal:0,2` لا يُقَصُّ بصمت.
 */
final class MoneyInput
{
    /**
     * @param  string  $name  the model's VIRTUAL major-unit attribute (`price`), never the `*_minor` column
     * @param  Closure|string|null  $currency  رمزُ العملة (QAR…)، أو دالّةٌ تُقيَّمُ
     *                                         بحقنِ Filament (`Get`، `$record`) وتُعيدُه.
     */
    public static function make(string $name, Closure|string|null $currency, int $maxMinor = 100_000_000): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->minValue(0)
            ->maxValue(intdiv($maxMinor, MinorUnits::scale()))
            ->step(0.01)
            ->rule('decimal:0,2')
            ->suffix(fn (TextInput $component): string => self::currencyLabel($component->evaluate($currency)))
            ->formatStateUsing(fn (mixed $state, ?Model $record, TextInput $component): mixed => $record?->getAttribute($component->getName()) ?? $state);
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
