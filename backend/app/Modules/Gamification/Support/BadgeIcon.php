<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

/**
 * أيقوناتُ الشارات — القائمةُ المغلقةُ التي يختارُ منها المشغِّل.
 *
 * ⚠️ كانَ الحقلُ نصّاً حرّاً، والأسماءُ الأربعةُ التي يكتبُها
 * `GamificationCatalogSeeder` هي كلُّ ما عُرِف. هي أسماءُ Heroicons، فتُعرَضُ في
 * اللوحةِ معاينةً. `BadgeIconTest` يتحقّقُ أنّ كلَّ أيقونةٍ في البذرةِ من هذه
 * القائمة، فلا تُضافُ شارةٌ مبذورةٌ بأيقونةٍ لا يعرفُها المنتقي.
 *
 * ⚠️ ورسمُها على شاشةِ الطالبِ خارجَ هذه القائمة — هذه القائمةُ تمنعُ النصَّ
 * الحرَّ في اللوحةِ فقط.
 */
final class BadgeIcon
{
    /** @var array<string, string> heroicon name => Arabic label */
    public const LABELS = [
        'sparkles' => 'نجوم لامعة',
        'fire' => 'شعلة',
        'calendar' => 'تقويم',
        'trophy' => 'كأس',
    ];
}
