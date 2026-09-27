<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

/**
 * أسماءُ الأيقوناتِ التي يفهمُها السوقُ العامّ — مكتوبةٌ هنا مرّةً للخادم.
 *
 * ⛔ الواجهةُ ترسمُ `subjects.icon` من خريطةِ `BY_ICON_NAME` في
 * `frontend/src/components/marketplace/subject-icon.ts`، وكلُّ اسمٍ خارجَها يسقطُ
 * إلى الرمزِ الاحتياطيِّ بلا خطأ. كانَ الحقلُ نصّاً حرّاً في اللوحة: مشغِّلٌ يكتبُ
 * «math» أو «📐» يرى «تم الحفظ» وسوقاً لم يتغيّر. الآن قائمةٌ مغلقةٌ بهذه
 * المفاتيحِ وحدَها، و`SubjectIconParityTest` يقارنُها بخريطةِ الواجهةِ حرفاً —
 * مفتاحٌ يُضافُ إلى إحداهما دونَ الأخرى يُسقِطُ البناء.
 *
 * والأسماءُ نفسُها أسماءُ Heroicons، فتُعرَضُ في اللوحةِ معاينةً بجانبِ كلِّ خيار.
 */
final class SubjectIcon
{
    /** @var array<string, string> key => what it shows, in Arabic */
    public const LABELS = [
        'calculator' => 'آلة حاسبة — الرياضيات',
        'beaker' => 'دورق — العلوم والكيمياء',
        'book-open' => 'كتاب مفتوح — المواد الأدبية',
        'language' => 'لغات',
        'computer-desktop' => 'حاسوب — الحاسب الآلي',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }
}
