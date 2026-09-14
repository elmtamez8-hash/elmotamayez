<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\CourseWaitlistEntry;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * التسجيلُ في دَورِ كورسٍ مكتمل (٠٣٤ · FR-026 · FR-026أ · FR-027).
 *
 * ⚠️ **والمُسجَّلُ هو الطالبُ نفسُه أو وليٌّ عن ابنِه، وكلا الطرفَينِ عطبٌ بلا
 * هذا البند.** مأخوذاً من الحسابِ الضاغطِ وحدَه **تصطفُّ الأمُّ ولا يُدعى الابنُ
 * أبداً** — وهو سردُ US4 حرفيّاً؛ ومأخوذاً مُعرِّفاً من العميلِ بلا حارسٍ فهو
 * **بابُ استعلامٍ عن هويّةِ أيِّ طالبٍ على المنصّة**. فالمُعرِّفُ يُقبَلُ ويُسأَلُ
 * عنه دليلُ الأولياء، **والرفضُ واحدٌ في الحالتَين**: معرِّفٌ لا وجودَ له ومعرِّفٌ
 * لطالبٍ ليسَ ابنَه يُجابانِ بالجملةِ نفسِها، لأنّ جواباً مختلفاً لكلٍّ منهما
 * عرّافٌ يقولُ للسائلِ أيُّ المعرِّفاتِ حقيقيّ.
 *
 * ⚠️ **ولا يُسجَّلُ في دَورٍ إلّا على كورسٍ مكتمل.** {@see CohortDirectory::courseIsFull()}
 * هو الإملاءُ الواحدُ الذي يقرؤُه بابُ الشراءِ وبابُ المجّانيِّ والبطاقةُ العامّةُ
 * وهذا — وصفٌّ في دَورِ كورسٍ فيه مقعدٌ شاغرٌ يُخفي عن الطالبِ أنّه يستطيعُ
 * الدخولَ الآن.
 *
 * ⚠️ **ولا موضعَ في الجواب** (FR-027): رقمٌ يُرسَلُ يُقرَأُ حجزاً، والدَّورُ لا
 * يحجزُ مقعداً ولا يَعِدُ به. وترتيبُه مشتقٌّ من وقتِ إنشائِه لا من عمودٍ مخزَّن.
 *
 * ⚠️ **و`workspace_id` يُكتَبُ من الكورسِ صراحةً**: صاحبُ الصفِّ عضوٌ في لا
 * مساحة، فسياقُه `null` والملءُ التلقائيُّ لا يقعُ أبداً — ويبقى العمودُ صفراً
 * وتقرأُ اللوحةُ قائمةً فارغة.
 */
class JoinWaitlist extends Action
{
    public function __construct(
        private readonly CohortDirectory $cohorts,
        private readonly EnrollmentDirectory $enrollments,
        private readonly GuardianDirectory $guardians,
    ) {}

    public function handle(User $actor, Course $course, ?string $studentUuid = null): CourseWaitlistEntry
    {
        $student = $this->resolveStudent($actor, $studentUuid);
        $courseId = (int) $course->getKey();

        if ($this->enrollments->hasActiveEnrollment($student, $courseId)) {
            throw new DomainException('أنت مسجَّل في هذا الكورس بالفعل.');
        }

        if (! $this->cohorts->courseIsFull($courseId)) {
            throw new DomainException('في هذا الكورس مكان الآن — سجِّل فيه مباشرةً، ولا حاجة إلى الدَّور.');
        }

        /*
        | ⚠️ **الفهرسُ الفريدُ هو الحارس.** ضغطتانِ في اللحظةِ نفسِها تمرّانِ من
        | أيِّ قراءةٍ تسبقُ الكتابة، والإدراجُ هو موضعُ الخسارة. فالخاسرُ يُرَدُّ
        | إليه الصفُّ القائمُ بدلَ خطأِ مفتاحٍ مكرَّرٍ يقرؤُه الطالبُ عطباً —
        | وهو الشكلُ الوحيدُ المسموحُ للالتقاطِ هنا: **قراءةٌ بعدَه، ورميٌ إن لم
        | يُوجَدْ صفّ**، فلا يختلطُ التكرارُ بفشلٍ حقيقيٍّ (مفتاحٌ أجنبيٌّ، عمودٌ
        | فارغ) — وهي قاعدةُ `CreditLedger::writeEntry()` من بابٍ آخر.
        */
        try {
            return CourseWaitlistEntry::query()->create([
                'workspace_id' => (int) $course->workspace_id,
                'course_id' => $courseId,
                'student_user_id' => $student->getKey(),
                'registered_by_user_id' => $actor->getKey(),
            ]);
        } catch (QueryException $e) {
            $existing = CourseWaitlistEntry::query()
                ->withoutWorkspaceScope()
                ->where('course_id', $courseId)
                ->where('student_user_id', $student->getKey())
                ->where('closed_slot', 0)
                ->first();

            if ($existing instanceof CourseWaitlistEntry) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * ⚠️ جملةُ رفضٍ واحدةٌ لحالتَين — انظرْ وصفَ الصنف.
     */
    private function resolveStudent(User $actor, ?string $studentUuid): User
    {
        if ($studentUuid === null || $studentUuid === '' || $studentUuid === $actor->uuid) {
            return $actor;
        }

        $student = User::query()->where('uuid', $studentUuid)->first();

        if (! $student instanceof User || ! $this->guardians->isAuthorised(
            $actor,
            $student,
            /*
            | ⚠️ صلاحيّةُ المال، لا الجدول. الدَّورُ ينتهي بدعوةٍ إلى **شراءِ**
            | مقعد، فمن يقرّرُ أيَّ الكورساتِ يُشترى للابنِ هو من يصطفُّ له —
            | و«الجدول» إطّلاعٌ على مواعيدَ قائمةٍ لا قرارٌ بالتحاقٍ جديد.
            | وسابقتُه `RecordTermsConsent` قبلَ أن يُولَدَ `DataRights`.
            */
            GuardianPermission::Payments,
        )) {
            throw new DomainException('لا تملك التسجيل في الدَّور باسم هذا الطالب.');
        }

        return $student;
    }
}
