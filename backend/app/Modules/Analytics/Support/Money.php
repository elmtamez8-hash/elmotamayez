<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use App\Modules\Payments\Enums\Currency;

/**
 * صياغةُ مبلغٍ بوحداتٍ صغرى — في مكانٍ واحد.
 *
 * ⛔ **ولا يُجمَعُ مبلغانِ بعملتَين.** قِيسَ على الإنتاج 2026-09-04: حركاتُ الدفعِ
 * المحصَّلةُ فيها `USD` و`QAR` معاً، فـ`SUM(amount_minor)` عبرَهما رقمٌ لا معنى
 * له يُعرَضُ بثقةٍ على شاشةِ إدارة. كلُّ قراءةٍ ماليّةٍ هنا تُجمِّعُ **بالعملة**،
 * وتعرضُ صفّاً لكلِّ واحدةٍ منها.
 */
final class Money
{
    public static function format(int $minor, string $currency): string
    {
        $symbol = Currency::tryFrom($currency)?->short() ?? $currency;

        return number_format($minor / 100, 2).' '.$symbol;
    }

    /**
     * صفوفُ العملاتِ في سطرٍ واحد.
     *
     * ⚠️ للخانةِ الواحدةِ في جدولٍ فقط. **لا تُستعملْ لعدّادٍ رئيسيّ**: سطرٌ يضمُّ
     * عملتَينِ داخلَ رقمٍ كبيرٍ لا يُقرَأ، وهو ما أُبلِغَ عنه في عدّادِ «المحصَّل».
     * العدّادُ صارَ عدّاداً لكلِّ عملة.
     *
     * @param  iterable<array{currency: string, total: int|float|string}>  $rows
     */
    public static function line(iterable $rows, string $emptyLabel = '—'): string
    {
        $parts = [];

        foreach ($rows as $row) {
            $parts[] = self::format((int) $row['total'], (string) $row['currency']);
        }

        return $parts === [] ? $emptyLabel : implode(' · ', $parts);
    }
}
