<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

/**
 * قاعدةُ نافذةِ التوفّرِ الأسبوعيّة، وهجاؤها واحدٌ مهما اختلفَ الباب.
 *
 * ⚠️ بابانِ يكتبانِ الصفوفَ نفسَها: الخطوةُ الرابعةُ من معالجِ الانضمام، وشاشةُ
 * «مواعيدي الأسبوعيّة» بعدَ الاعتماد. ونسختانِ من القاعدةِ الواحدةِ تفترقانِ عندَ
 * أوّلِ تعديل، ثمّ يقبلُ أحدُ البابَينِ ما يرفضُه الآخرُ بلا أن يفشلَ شيء — وهو
 * السببُ عينُه الذي أخرجَ {@see TeacherListingRules} من طلبِ الخطوةِ الثانية.
 *
 * ⚠️ و`min:1` ليسَ سهواً: أسبوعٌ فارغٌ يعني مدرّساً لا يُحجَزُ عندَه شيءٌ إطلاقاً —
 * لا حصّةَ خاصّةً ولا توليدَ حصصٍ لمجموعة — وطريقُ الإجازةِ في هذا المنتَجِ فترةُ
 * تجميدٍ (`FreezePeriod`) لا مسحُ الجدول: التجميدُ يُقرَأُ ولا يُكتَبُ عليه شيء،
 * فتعودُ المواعيدُ بقيمِها بعدَه بلا سطرِ استئناف.
 *
 * ⚠️ ولا تحويلَ منطقةٍ زمنيّةٍ هنا ولا في المتحكّم: العمودُ UTC وخمسةُ قرّاءٍ
 * يقرؤونَه كذلك، والعميلُ يحوّلُ بـ`toUtcSlot` قبلَ الإرسال. تحويلٌ «مساعِدٌ» على
 * الخادمِ يُزيحُ كلَّ فترةٍ مرّتَين.
 */
final class AvailabilityRules
{
    /** @return array<string, array<int, mixed>> */
    public static function fields(string $key = 'availability'): array
    {
        return [
            $key => ['required', 'array', 'min:1'],
            $key.'.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            $key.'.*.start_time' => ['required', 'date_format:H:i,H:i:s'],
            $key.'.*.end_time' => ['required', 'date_format:H:i,H:i:s'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $key = 'availability'): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            $key.'.min' => 'أضف فترة توفّر واحدة على الأقل.',
            $key.'.*.start_time.date_format' => 'صيغة الوقت غير صحيحة.',
            $key.'.*.end_time.date_format' => 'صيغة الوقت غير صحيحة.',
        ];
    }
}
