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
 * يُسنَدْ بعدُ ومَن يُسنِد — والطالبُ لا يختارُ، الإدارةُ تُسنِد.
 *
 * ⛔ **ومقصدُ FR-028ب باقٍ بأقوى صورةٍ ممكنة.** كانَ الصمّامُ يفتحُ المنهجَ حينَ
 * لا تبقى مجموعةٌ يستطيعُ الانضمامَ إليها — أي أنّ الشرطَ لا يقفُ إلّا وله فعلٌ
 * يُحقِّقُه. والآنَ لا شرطَ أصلاً، فالحالةُ التي كانَ الصمّامُ يحرسُ منها غيرُ
 * قابلةٍ للإنتاجِ بأيِّ طريق.
 *
 * ⚠️ **و`joinableCohortsExist()` تبقى، ولها عملٌ مختلف.** لم تعُدْ تقرّرُ قفلاً؛
 * هي التي تُفرِّقُ في {@see message()} بينَ «مجموعاتٌ متاحةٌ ولستَ فيها» و«لا
 * مجموعةَ أصلاً» — وهما جملتانِ مختلفتانِ للطالب، ويقرؤُهما من الحمولةِ لا
 * يشتقُّهما بـTypeScript.
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
     * ⚠️ **الجملةُ تُسمّي مَن يُسنِد، والمنهجُ مفتوحٌ تحتَها في الحالتَين**
     * (٠٣٤ · FR-015 · FR-012). كانَ النصُّ «اختر مجموعتك للبدء» أمراً بفعلٍ
     * صارَ ليسَ فعلَ الطالب، و«راجعْ مدرّسك» تُسمّي الفاعلَ الخطأ: الإدارةُ هي
     * التي تُسنِد. ورفضٌ صامتٌ أو قائمةٌ بلا تفسيرٍ يُقرَآنِ عُطلاً.
     *
     * ⚠️ **وهما جملتانِ لا واحدة.** «مجموعاتٌ متاحةٌ ولستَ فيها» غيرُ «لا
     * مجموعةَ أصلاً»: الأولى انتظارُ إسناد، والثانيةُ لا شيءَ يُنتظَرُ فيه بعد.
     */
    public function message(): ?string
    {
        if (! $this->required || $this->satisfied) {
            return null;
        }

        return $this->joinableExists
            ? 'لم تُسنَد إلى مجموعة بعد — إدارة المنصّة هي من تُسنِدك. المنهج مفتوح لك حتى ذلك الحين.'
            : 'لا توجد مجموعة مفتوحة في هذه المادّة الآن — تُسنِدك الإدارة حين تُفتح واحدة. المنهج مفتوح لك حتى ذلك الحين.';
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
