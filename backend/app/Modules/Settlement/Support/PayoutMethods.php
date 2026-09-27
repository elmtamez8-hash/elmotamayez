<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

/**
 * كيفَ خرجَ مالُ المدرّس — القائمةُ التي يختارُ منها الموظَّف.
 *
 * ⚠️ القيمةُ المخزَّنةُ هي الجملةُ العربيّةُ نفسُها، لا رمزٌ إنجليزيّ. كانَ الحقلُ
 * نصّاً حرّاً (`teacher_payouts.method`، ‏٣٢ حرفاً) وكُتِبَت فيه «تحويل بنكي»
 * بالعربيّة، ومسارُ الـAPI يقبلُ أيَّ نصٍّ حتّى ٣٢ حرفاً، وسجلُّ التدقيقِ يطبعُ
 * الخاصّيّةَ كما هي. قيمةٌ عربيّةٌ تُبقي الصفوفَ القديمةَ والجديدةَ والمسارَين
 * متّفقةً بلا ترجمةٍ في أيِّ قارئ.
 *
 * ⛔ ولا استيرادَ لتسمياتِ وسائلِ الدفعِ من وحدةِ الفوترةِ وإن تطابقَ اسمان:
 * التسويةُ لا تسمّي سياقَ الفوترةِ أصلاً (`ContextIsolationTest`).
 */
final class PayoutMethods
{
    /** @var list<string> */
    public const ALL = [
        'تحويل بنكي',
        'محفظة إلكترونية',
        'نقدي',
        'أخرى',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(self::ALL, self::ALL);
    }
}
