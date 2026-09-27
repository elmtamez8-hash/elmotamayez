<?php

declare(strict_types=1);

namespace App\Filament;

/**
 * مجموعاتُ قائمةِ `/admin` — الإملاءُ الوحيدُ لكلِّ اسمٍ، والترتيبُ الوحيد.
 *
 * ⚠️ **ثابتٌ لا نصٌّ مكرَّر.** Filament يطابقُ المجموعةَ بالنصِّ حرفاً بحرف،
 * فمحرفُ تشكيلٍ واحدٌ مختلفٌ في موردٍ واحدٍ يُنشئُ مجموعةً ثانيةً بالاسمِ نفسِه
 * تقريباً ويضعُ فيها ذلك الموردَ وحدَه — بلا خطأٍ ولا تحذير. كلُّ موردٍ وصفحةٍ
 * يُسمّي مجموعتَه من هنا، و{@see self::ordered()} هو ما تُعلِنُه
 * `AdminPanelProvider::navigationGroups()`، و`AdminNavigationTest` يُسقِطُ البناءَ
 * إن ظهرَ في القائمةِ اسمٌ غيرُ مُعلَن.
 *
 * الترتيبُ قرارُ المالك (٢٠٢٦-٠٩-٢٧): ما ينتظرُ قراراً أوّلاً، ثمّ الكتالوج
 * والمحتوى والناس والمال، والإعداداتُ آخراً.
 */
final class NavigationGroups
{
    /** الطوابيرُ التي تنتظرُ قراراً من إنسان — وكلُّ بندٍ فيها يحملُ عدّاداً. */
    public const DECISIONS = 'ينتظر قرارك';

    public const TEACHERS_AND_MARKET = 'المدرّسون والسوق';

    public const CONTENT = 'المحتوى والتعلّم';

    public const STUDENTS_AND_ACCOUNTS = 'الطلاب والحسابات';

    public const MONEY = 'المال والاشتراكات';

    public const TEACHER_EARNINGS = 'مستحقّات المدرّسين';

    public const NOTIFICATIONS = 'الإشعارات';

    public const GAMIFICATION = 'التلعيب';

    public const COMPLIANCE = 'الامتثال';

    public const SETTINGS_AND_ACCESS = 'الإعدادات والوصول';

    /** @return list<string> */
    public static function ordered(): array
    {
        return [
            self::DECISIONS,
            self::TEACHERS_AND_MARKET,
            self::CONTENT,
            self::STUDENTS_AND_ACCOUNTS,
            self::MONEY,
            self::TEACHER_EARNINGS,
            self::NOTIFICATIONS,
            self::GAMIFICATION,
            self::COMPLIANCE,
            self::SETTINGS_AND_ACCESS,
        ];
    }
}
