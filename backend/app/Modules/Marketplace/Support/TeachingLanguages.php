<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

/**
 * اللغاتُ التي يجوزُ للمدرّسِ أن يُعلنَ التدريسَ بها — قائمةٌ واحدةٌ للبابِ
 * وللشاشة.
 *
 * ⚠️ كانت قائمتَين: ثابتٌ خاصٌّ داخلَ {@see TeacherStepTwoRequest} يحرسُ الـAPI،
 * و`LANGUAGES` في `TeacherSignupWizard.tsx` يحملُ المقابلَ العربيَّ للمعالج. ثمّ
 * جاءت لوحةُ الإدارةِ بحقلٍ حرٍّ بلا قائمةٍ أصلاً — فمراجِعٌ يكتبُ «عربي» أو
 * «Arabic» يُنتِجُ صفّاً **يرفضُه بابُ المعالجِ نفسُه** إن أعادَ المدرّسُ الإرسالَ،
 * ولا يجدُه مرشِّحُ السوقِ أبداً: `ListPublicTeachers` يبحثُ بـ
 * `whereJsonContains('teaching_languages', 'ar')` حرفاً بحرف. لا خطأ، ولا سطرَ
 * في سجلّ — مدرّسٌ يختفي من مرشِّحٍ ظنَّ أنّه فيه.
 *
 * ورابعةٌ تُضافُ هنا ليست صفّاً في قاعدةِ البيانات: هي ترجمةُ المنتَجِ كلِّه.
 * ونسخةُ الواجهةِ تبقى — زمنُ تشغيلٍ آخرُ لا يقرأُ PHP — وتعليقُها يقولُ ذلك.
 */
final class TeachingLanguages
{
    /** @var array<string, string> */
    private const OPTIONS = [
        'ar' => 'العربية',
        'en' => 'الإنجليزية',
        'fr' => 'الفرنسية',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::OPTIONS;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function labelFor(string $code): string
    {
        return self::OPTIONS[$code] ?? $code;
    }
}
