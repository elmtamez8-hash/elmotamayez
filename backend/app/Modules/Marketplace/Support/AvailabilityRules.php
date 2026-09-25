<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Data\TeacherStepFourData;
use App\Modules\Marketplace\Models\AvailabilitySlot;

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
 * ⛔ ولا تحويلَ منطقةٍ زمنيّةٍ هنا ولا في العميل (٢٠٢٦-٠٩-٢٥): الفترةُ تُخزَّنُ
 * **كما كتبَها المدرّسُ على ساعتِه** ومعها اسمُ ساعتِه (`timezone`، اسمُ IANA
 * يرسلُه المتصفّح). كانتْ تُخزَّنُ UTC بإزاحةِ الأسبوعِ الحاليّ، وفترةٌ أسبوعيّةٌ
 * بتوقيتٍ عالميٍّ لا تقدرُ على منطقةٍ فيها توقيتٌ صيفيّ: مدرّسٌ في القاهرة كتبَ
 * «الثلاثاء ١٧:٠٠» فصارتْ حصصُه ١٦:٠٠ على ساعتِه من ٢٠٢٦-١٠-٢٩. كلُّ قارئٍ يحوّلُ
 * الآنَ لكلِّ تاريخٍ على حدة ({@see AvailabilitySlot}).
 *
 * ⚠️ و`timezone` **مطلوبٌ** لا اختياريّ: نسخةُ واجهةٍ قديمةٌ في تبويبٍ مفتوحٍ
 * ترسلُ قيمَ UTC بلا منطقة، وقَبولُها يعني قراءتَها ساعاتٍ محلّيّةً — إزاحةٌ بثلاثِ
 * ساعاتٍ بصمت. رفضٌ بـ٤٢٢ يطلبُ تحديثَ الصفحةِ أرخصُ بكثير.
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
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
        ];
    }

    /**
     * الشكلُ الواحدُ الذي تُخزَّنُ به الفترة، مهما كتبَها البابُ الذي جاءتْ منه.
     *
     * ⚠️ القاعدةُ أعلاه تقبلُ `H:i` و`H:i:s` كليهما، فالبابانِ يُسلِّمانِ شكلَينِ
     * مختلفَينِ لوقتٍ واحد: معالجُ الانضمامِ كانَ يُسوّي داخلَ
     * {@see TeacherStepFourData} وشاشةُ «مواعيدي»
     * تُمرِّرُ ما وصلَها حرفيّاً. و«١٦:٠٠» و«١٦:٠٠:٠٠» يجبُ أن يتساويا — وإلّا
     * اختلفَ نصُّ الخطوةِ الرابعةِ عن نصِّ الصفِّ للساعةِ نفسِها.
     *
     * ⚠️ وMySQL يُسوّي عمودَ `time` من تلقاءِ نفسِه فيُخفي الفرقَ في الإنتاج،
     * بينما SQLite يحفظُ ما أُعطِيَ حرفيّاً — أي أنّ الاختلافَ يظهرُ في بيئةِ
     * التطويرِ وحدَها، وهي البيئةُ التي يُقرَأُ فيها هذا العمودُ بالمقارنةِ النصّيّة.
     *
     * @param  array<int, mixed>  $slots
     * @return list<array{day_of_week: int, start_time: string, end_time: string}>
     */
    public static function normalise(array $slots): array
    {
        return array_values(array_map(function ($slot): array {
            $slot = (array) $slot;

            return [
                'day_of_week' => (int) $slot['day_of_week'],
                'start_time' => self::seconds((string) $slot['start_time']),
                'end_time' => self::seconds((string) $slot['end_time']),
            ];
        }, $slots));
    }

    /** «١٦:٠٠» و«١٦:٠٠:٠٠» ساعةٌ واحدة، فتُخزَّنُ بشكلٍ واحد. */
    public static function seconds(string $time): string
    {
        return substr_count($time, ':') === 1 ? $time.':00' : $time;
    }

    /** @return array<string, string> */
    public static function messages(string $key = 'availability'): array
    {
        return [
            'required' => 'هذا الحقل مطلوب.',
            $key.'.min' => 'أضف فترة توفّر واحدة على الأقل.',
            $key.'.*.start_time.date_format' => 'صيغة الوقت غير صحيحة.',
            $key.'.*.end_time.date_format' => 'صيغة الوقت غير صحيحة.',
            'timezone.required' => 'حدّث الصفحة ثم أعد الحفظ — لم تصل منطقتك الزمنية.',
            'timezone.timezone' => 'المنطقة الزمنية غير معروفة.',
        ];
    }
}
