<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Shared\Contracts\CohortDirectory;

/**
 * «لم تُسنَدْ إلى مجموعةٍ بعد» — حالةُ العضويّةِ كما تُقالُ للطالب، **ولا قفلَ
 * خلفَها** (٠٣٤ · FR-015).
 *
 * ⛔ **هذا الصنفُ كانَ يحرسُ، وصارَ يَصِف.** كانَ فيه `locks()` تردُّ «نعم» متى
 * وُجِدَت مجموعةٌ صالحةٌ للانضمامِ ولم يكنِ الطالبُ في واحدة، و{@see LessonGate}
 * يرفضُ بها كلَّ درسٍ بـ`no_cohort` — تنفيذاً لـ٠٢١ · FR-028أ. و٠٣٤ · FR-015
 * **تُلغي ذلكَ الشرطَ نصّاً**: «يُلغي هذا شرطَ ٠٢١ · FR-028أ ويُبقي مقصدَ
 * FR-028ب». فالمنهجُ يُفتَحُ لطالبٍ لا مجموعةَ له، وتعلوه جملةٌ تقولُ إنّه لم
 * يُسنَدْ بعدُ **ومَن يستطيعُ أن يضعَه**.
 *
 * ⛔ **وفاعلانِ لا فاعلٌ واحد — قرارُ المالكِ ٢٠٢٦-٠٩-١٤.** كُتِبَت هذه الجملةُ
 * أوّلاً «الإدارةُ وحدَها تُسنِد، والطالبُ لا يختار»، ثمّ نُقِضَ ذلكَ صراحةً:
 * **للطالبِ أن ينضمَّ بنفسِه إلى مجموعةٍ مفتوحة** ما دامَ ليسَ في مجموعةٍ أخرى
 * من الكورسِ نفسِه، **وله طلبُ التبديل**. وإسنادُ الإدارةِ (FR-001) يبقى بابَينِ
 * مبنيَّينِ فوقَ ذلك: شاشةُ `/admin` وحقلُ المجموعةِ على الاعتماد. فالجملةُ
 * تُسمّي الطريقَينِ معاً — وجملةٌ تُسمّي واحداً منهما تتركُ الطالبَ ينتظرُ أمامَ
 * زرٍّ يعمل، أو تدفعُه إلى انتظارٍ لا لزومَ له.
 *
 * ⚠️ **و`joinableCohortsExist()` تبقى، ولها عملٌ مختلف.** لم تعُدْ تقرّرُ قفلاً؛
 * هي التي تُفرِّقُ في {@see message()} بينَ «مجموعاتٌ متاحةٌ» و«لا مجموعةَ
 * أصلاً» — وهما جملتانِ مختلفتانِ للطالب، ويقرؤُهما من الحمولةِ لا يشتقُّهما
 * بـTypeScript.
 */
final class CohortGate
{
    private function __construct(
        /** Does this course run in groups? (An archived group still means yes.) */
        public readonly bool $required,
        /**
         * Is the condition MET?
         *
         * ⚠️ NOT «HAS A MEMBERSHIP». On a course with no groups at all there is
         * nothing to satisfy, so this is `true` — a payload reading
         * `required: false, satisfied: false` invites a screen to tell a student
         * they are not in a group on a course that has none. Which group they
         * are actually in is `/courses/{course}/cohorts`, which is the question
         * the switcher asks.
         */
        public readonly bool $satisfied,
        /** Is there one they could join at this instant? */
        public readonly bool $joinableExists,
    ) {}

    /** The full block the course page reads, including the sentence. */
    public static function describe(User $student, int $courseId): self
    {
        $directory = app(CohortDirectory::class);

        $required = $directory->coursesWithCohorts([$courseId]) !== [];

        return new self(
            required: $required,
            satisfied: ! $required || $directory->hasOpenMembership($student, $courseId),
            joinableExists: $directory->joinableCohortsExist($courseId),
        );
    }

    /**
     * ما يُقالُ للطالب.
     *
     * ⚠️ **الجملةُ تُسمّي كلَّ طريقٍ مفتوحٍ إليه، والمنهجُ مفتوحٌ تحتَها في
     * الحالتَين** (٠٣٤ · FR-015 · FR-012). كانَ النصُّ «اختر مجموعتك للبدء»
     * أمراً بفعلٍ قد لا يكونُ متاحاً، و«راجعْ مدرّسك» تُسمّي فاعلاً لا يملكُ
     * البتَّ وحدَه. ورفضٌ صامتٌ أو قائمةٌ بلا تفسيرٍ يُقرَآنِ عُطلاً.
     *
     * ⛔ **و«مفتوحة» خرجَت من الجملةِ الثانيةِ في ٠٣٦ · T051، والسببُ أنّها صارَت
     * كذبةً في حالةٍ جديدةٍ بالكامل.** `joinableExists` تضمُّ الآنَ شرطَ الثمنِ
     * النافذ، فمجموعاتٌ **مفتوحةٌ وفيها متّسعٌ** ولا باقةَ تصلُها تجعلُها `false`
     * — وكانَ الطالبُ يقرأُ «لا توجدُ مجموعةٌ مفتوحة» عن مجموعاتٍ مفتوحةٍ يراها
     * زملاؤُه، ويُحالُ إلى «تُسنِدك الإدارةُ حينَ تُفتَحُ واحدة»: فعلٌ لن يقعَ،
     * لأنّ المفقودَ تسعيرُ باقةٍ لا فتحُ مجموعة. والصياغةُ الآنَ صادقةٌ على
     * أسبابِ الغيابِ الثلاثةِ جميعاً — لا مجموعةَ · كلُّها ممتلئة · لا ثمنَ
     * يصلُها — **ولا تُسمّي السبب**: حالُ تسعيرِ باقةٍ حقيقةٌ بينَ المدرّسِ
     * والمنصّة، لا تخصُّ الطالب.
     *
     * ⚠️ **وهما جملتانِ لا واحدة.** «توجدُ مجموعةٌ مفتوحة» غيرُ «لا مجموعةَ
     * أصلاً»: في الأولى للطالبِ فعلٌ يفعلُه الآن — ينضمُّ بنفسِه — أو ينتظرُ
     * إسناداً؛ وفي الثانيةِ لا شيءَ يُفعَلُ بعدُ إلّا الانتظار. وخلطُهما يُعطي
     * الطالبَ جملةً واحدةً صحيحةً في حالةٍ وكاذبةً في الأخرى.
     */
    public function message(): ?string
    {
        if (! $this->required || $this->satisfied) {
            return null;
        }

        return $this->joinableExists
            ? 'لم تُسنَد إلى مجموعة بعد — انضمّ إلى مجموعة مفتوحة من القائمة، أو تُسنِدك إدارة المنصّة. المنهج مفتوح لك في الحالتين.'
            : 'لا توجد مجموعة متاحة للانضمام في هذه المادّة الآن — تُسنِدك الإدارة حين تتاح واحدة. المنهج مفتوح لك حتى ذلك الحين.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'required' => $this->required,
            'satisfied' => $this->satisfied,
            'joinable_exists' => $this->joinableExists,
            'message' => $this->message(),
        ];
    }
}
