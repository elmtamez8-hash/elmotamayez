<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Support\LessonRelease;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * «لمن هذا العنصرُ ومتى يظهر» — الكاتبُ الوحيدُ للمحورَين.
 *
 * ⛔ **والمفتاحُ الغائبُ صمتٌ، والقيمةُ الفارغةُ تعليمة.** غيابُ
 * `cohort_uuids` يعني «لم أذكرِ المحورَ»، والمصفوفةُ الفارغةُ تعني «ألغِ
 * التضييق»؛ وغيابُ `release_session_uuid` صمتٌ كذلك، و`null` تعني «فُكَّ
 * الربطَ فليظهرْ الآن» — وهو مخرجُ FR-008 لحصّةٍ لم تُسلَّمْ ولم تُلغَ. ولو
 * قُرِئَ الفارغُ «لا أحدَ يراه» لصارَ للمدرّسِ زرٌّ يُخفي عنصراً عن كلِّ
 * الطلابِ بلا طريقةٍ لاستعادتِه.
 *
 * ⚠️ **والمحورانِ في فعلٍ واحدٍ ومعاملةٍ واحدةٍ وحدثٍ واحد.** كلاهما يُخرِجُ
 * العنصرَ من مقامِ التقدُّمِ ويُدخِلُه، فحفظٌ يُغيِّرُ الاثنَينِ ويُطلِقُ
 * حدثَينِ يُعيدُ حسابَ كلِّ تسجيلاتِ الكورسِ مرّتَين.
 *
 * ⚠️ **والحارسُ هنا لا في الطلب.** `WorkspaceRules::exists` في
 * `UpdateLessonRequest` يصوغُ الرسالةَ بالعربيّةِ تحتَ حقلِها، وهذا هو البابُ
 * الذي تمرُّ منه البذورُ ولوحةُ الإدارةُ وأيُّ سطحٍ لاحقٍ بلا نموذجِ طلب.
 */
class SaveLessonAudience extends Action
{
    public function __construct(private readonly CohortDirectory $cohorts) {}

    /**
     * @param  array{cohort_uuids?: list<string>, release_session_uuid?: string|null}  $wanted
     * @return bool هل تغيّرَ شيءٌ فعلاً
     */
    public function handle(Lesson $lesson, array $wanted): bool
    {
        $courseId = (int) $lesson->course_id;

        $cohorts = array_key_exists('cohort_uuids', $wanted)
            ? $this->cohortIds($wanted['cohort_uuids'], $courseId)
            : null;

        $release = array_key_exists('release_session_uuid', $wanted)
            ? $this->sessionId($wanted['release_session_uuid'], $courseId)
            : false;

        $cohortsChanged = $cohorts !== null && $cohorts !== $this->currentCohortIds($lesson);
        $releaseChanged = $release !== false && $release !== (
            $lesson->release_session_id === null ? null : (int) $lesson->release_session_id
        );

        if (! $cohortsChanged && ! $releaseChanged) {
            return false;
        }

        DB::transaction(function () use ($lesson, $cohorts, $cohortsChanged, $release, $releaseChanged): void {
            if ($cohortsChanged) {
                LessonCohortScope::query()
                    ->withoutWorkspaceScope()
                    ->where('lesson_id', $lesson->getKey())
                    ->delete();

                /** @var list<int> $cohorts */
                foreach ($cohorts as $cohortId) {
                    LessonCohortScope::query()->create([
                        'workspace_id' => $lesson->workspace_id,
                        'lesson_id' => $lesson->getKey(),
                        'cohort_id' => $cohortId,
                    ]);
                }
            }

            if ($releaseChanged) {
                // ⚠️ `forceFill`، فالعمودُ ليسَ في `$fillable` عمداً: هذا فعلُه
                // الوحيد، والإسنادُ الجماعيُّ **يُسقِطُ المفتاحَ غيرَ المسموحِ
                // في صمت** — لا استثناءَ ولا سطرَ سجلّ.
                $lesson->forceFill(['release_session_id' => $release])->save();
            }
        });

        /*
        | ⛔ **ويقعُ الحدثُ عندَ التغيُّرِ وحدَه، ومرّةً واحدةً للكورس.**
        | المحورانِ يُخرِجانِ العنصرَ من المقامِ ويُدخِلانِه، فنسبةُ كلِّ طالبٍ
        | مسجَّلٍ تتحرّك — و`progress_pct` لا يُكتَبُ إلّا عندَ إتمامِ درسٍ، فبلا
        | هذا الحدثِ يبقى الرقمُ محسوباً على مقامٍ لم يعُدْ موجوداً.
        |
        | ⚠️ **ومقارنةُ الحالِ بالحالِ قبلَه ليست تحسيناً**: المستمعُ يُعيدُ
        | مزامنةَ كلِّ تسجيلاتِ الكورس، فإطلاقُه على كلِّ حفظٍ يعني مزامنةً
        | كاملةً كلّما أعادَ المدرّسُ تسميةَ درسٍ وحفظَ الشاشةَ بما فيها.
        */
        // ⚠️ `withoutWorkspaceScope()` على العلاقةِ نفسِها: `Lesson::course()`
        // تجري تحتَ النطاقِ فتُرجِعُ `null` لقارئٍ سياقُه مساحةٌ أخرى — وحدثٌ
        // لا يقعُ لأنّ الكورسَ «غيرُ موجود» هو مقامٌ لا يُعادُ حسابُه أبداً.
        CourseStructureChanged::dispatch($lesson->course()->withoutWorkspaceScope()->firstOrFail());

        return true;
    }

    /**
     * @param  list<string>  $uuids
     * @return list<int> مرتَّبةٌ ليقارَنَ حالٌ بحال
     */
    private function cohortIds(array $uuids, int $courseId): array
    {
        $ids = [];

        foreach (array_unique($uuids) as $uuid) {
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

            $ids[$id] = $id;
        }

        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    private function sessionId(?string $uuid, int $courseId): ?int
    {
        if ($uuid === null) {
            return null;
        }

        $id = LessonRelease::resolveSessionId($uuid, $courseId);

        if ($id === null) {
            throw new DomainException('هذه الحصة ليست من حصص هذا الكورس.');
        }

        return $id;
    }

    /** @return list<int> */
    private function currentCohortIds(Lesson $lesson): array
    {
        $ids = DB::table('lesson_cohort_scopes')
            ->where('lesson_id', $lesson->getKey())
            ->pluck('cohort_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        sort($ids);

        return $ids;
    }
}
