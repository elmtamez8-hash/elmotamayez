<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * «هذا العنصرُ لهذه المجموعاتِ» — الكاتبُ الوحيدُ لمحورِ النطاق.
 *
 * ⛔ **ومصفوفةٌ فارغةٌ تُلغي التضييق، لا تعني «لا أحد».** غيابُ الصفِّ هو
 * «للجميع» (FR-001)، فالفارغُ يمحو الصفوفَ ويعيدُ العنصرَ إلى الحالِ الذي وُلِدَ
 * عليه — ولو قُرِئَ «لا مجموعةَ تراه» لصارَ للمدرّسِ زرٌّ يُخفي عنصراً عن كلِّ
 * الطلابِ بلا طريقةٍ لاستعادتِه.
 *
 * ⚠️ **والحارسُ هنا لا في الطلب.** `WorkspaceRules::exists` في
 * `UpdateLessonRequest` يصوغُ الرسالةَ بالعربيّةِ تحتَ حقلِها، وهذا هو البابُ
 * الذي تمرُّ منه البذورُ ولوحةُ الإدارةُ وأيُّ سطحٍ لاحقٍ بلا نموذجِ طلب.
 */
class SaveLessonAudience extends Action
{
    public function __construct(private readonly CohortDirectory $cohorts) {}

    /**
     * @param  list<string>  $cohortUuids  فارغةٌ = للجميع
     * @return bool هل تغيّرَ شيءٌ فعلاً
     */
    public function handle(Lesson $lesson, array $cohortUuids): bool
    {
        $courseId = (int) $lesson->course_id;

        $wanted = [];

        foreach (array_unique($cohortUuids) as $uuid) {
            /*
            | ⚠️ **والكورسُ جزءٌ من السؤالِ لا مجاملة.** `resolveCohortId()`
            | يجيبُ `null` لمعرّفٍ من كورسٍ آخرَ كما يجيبُ لمعرّفٍ لا وجودَ له —
            | جوابانِ مختلفانِ هنا عرّافٌ يقولُ أيُّ المعرّفاتِ حقيقيّة. وبدونَه
            | يستطيعُ مدرّسٌ أن يقصرَ عنصرَه على مجموعةٍ عندَ مدرّسٍ آخر، فلا
            | يراه أحدٌ من طلابِه أبداً.
            */
            $id = $this->cohorts->resolveCohortId((string) $uuid, $courseId);

            if ($id === null) {
                throw new DomainException('هذه المجموعة ليست من مجموعات هذا الكورس.');
            }

            $wanted[$id] = $id;
        }

        $current = DB::table('lesson_cohort_scopes')
            ->where('lesson_id', $lesson->getKey())
            ->pluck('cohort_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        sort($current);
        $next = array_values($wanted);
        sort($next);

        if ($current === $next) {
            return false;
        }

        DB::transaction(function () use ($lesson, $wanted): void {
            LessonCohortScope::query()
                ->withoutWorkspaceScope()
                ->where('lesson_id', $lesson->getKey())
                ->delete();

            foreach ($wanted as $cohortId) {
                LessonCohortScope::query()->create([
                    'workspace_id' => $lesson->workspace_id,
                    'lesson_id' => $lesson->getKey(),
                    'cohort_id' => $cohortId,
                ]);
            }
        });

        /*
        | ⛔ **ويقعُ الحدثُ عندَ التغيُّرِ وحدَه، ومرّةً واحدةً للكورس.**
        | التضييقُ يُخرِجُ العنصرَ من المقامِ ويُدخِلُه، فنسبةُ كلِّ طالبٍ
        | مسجَّلٍ تتحرّك — و`progress_pct` لا يُكتَبُ إلّا عندَ إتمامِ درسٍ، فبلا
        | هذا الحدثِ يبقى الرقمُ محسوباً على مقامٍ لم يعُدْ موجوداً.
        |
        | ⚠️ **ومقارنةُ المجموعتَينِ قبلَه ليست تحسيناً**: المستمعُ يُعيدُ مزامنةَ
        | كلِّ تسجيلاتِ الكورس، فإطلاقُه على كلِّ حفظٍ يعني مزامنةً كاملةً كلّما
        | أعادَ المدرّسُ تسميةَ درسٍ وحفظَ الشاشةَ بما فيها.
        */
        // ⚠️ `withoutWorkspaceScope()` على العلاقةِ نفسِها: `Lesson::course()`
        // تجري تحتَ النطاقِ فتُرجِعُ `null` لقارئٍ سياقُه مساحةٌ أخرى — وحدثٌ
        // لا يقعُ لأنّ الكورسَ «غيرُ موجود» هو مقامٌ لا يُعادُ حسابُه أبداً.
        CourseStructureChanged::dispatch($lesson->course()->withoutWorkspaceScope()->firstOrFail());

        return true;
    }
}
