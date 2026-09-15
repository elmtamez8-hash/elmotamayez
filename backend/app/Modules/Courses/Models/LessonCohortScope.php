<?php

declare(strict_types=1);

namespace App\Modules\Courses\Models;

use App\Models\BaseModel;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * «هذا العنصرُ لهذه المجموعة» — صفٌّ واحدٌ لكلِّ (عنصر، مجموعة).
 *
 * **وغيابُ كلِّ صفٍّ هو «للجميع»**، فلا حالةَ ثالثةَ تُقرَأُ ولا قيمةَ مبدئيّةَ
 * تُكتَب.
 *
 * ⚠️ **ويُكتَبُ من `SaveLessonAudience` وحدَها**، فهي التي تتحقّقُ أنّ المجموعةَ
 * من كورسِ الدرسِ نفسِه.
 *
 * ⛔ **ويُقرَأُ على مسارِ الطالبِ بـ`DB::table` لا من هنا.** `BelongsToWorkspace`
 * يضيفُ `WorkspaceScope`، و`WorkspaceContext::id()` يرجعُ إلى
 * `users.last_workspace_id` — وهو **مطبوعٌ على كلِّ طالبٍ أُضيفَ يوماً إلى
 * مساحةِ عمل** (ستّةُ صفوفٍ مقيسةٌ على قاعدةٍ حقيقيّة). فالقراءةُ المُنطَقةُ
 * تُرجِعُ صفوفاً أقلَّ لطالبٍ مختومٍ بمساحةٍ أخرى: مقامٌ يختلفُ باختلافِ
 * القارئِ، وهو نقضُ FR-013أ بالآلةِ الموضوعةِ لتحقيقِها — ولا تجهيزةَ بمساحةٍ
 * واحدةٍ تراه. والصنفُ يبقى للكتابةِ وللوحةِ الإدارة، حيثُ النطاقُ هو الحارسُ
 * المقصود.
 *
 * @property int $lesson_id
 * @property int $cohort_id
 * @property int $workspace_id
 */
class LessonCohortScope extends BaseModel
{
    use BelongsToWorkspace, HasUuid;

    protected $fillable = [
        'workspace_id',
        'lesson_id',
        'cohort_id',
    ];

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * نطاقُ كلِّ عنصرٍ من هذه القائمة، بالمعرّفاتِ العامّةِ لا بالداخليّة —
     * **للمؤلّفِ وحدَه**.
     *
     * ⚠️ **استعلامانِ للقائمةِ كلِّها مهما طالَت.** المورِدُ يعملُ مرّةً لكلِّ
     * صفّ، فسؤالٌ داخلَه هو N+1 بالبناء — وهي علّةُ `ClassSessionResource`
     * تصلُ من بابٍ جديد.
     *
     * @param  iterable<Lesson>  $lessons
     * @return array<int, list<string>>
     */
    public static function uuidsAmong(iterable $lessons): array
    {
        $ids = [];

        foreach ($lessons as $lesson) {
            $ids[] = (int) $lesson->getKey();
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, list<int>> $byLesson */
        $byLesson = [];
        $cohortIds = [];

        // `DB::table` وليسَ النموذجَ: انظرْ دفترَ هذا الصنفِ أعلاه.
        foreach (DB::table('lesson_cohort_scopes')->whereIn('lesson_id', $ids)->get(['lesson_id', 'cohort_id']) as $row) {
            $byLesson[(int) $row->lesson_id][] = (int) $row->cohort_id;
            $cohortIds[(int) $row->cohort_id] = (int) $row->cohort_id;
        }

        if ($byLesson === []) {
            return [];
        }

        $uuids = app(CohortDirectory::class)->uuidsFor(array_values($cohortIds));

        return array_map(
            static fn (array $ids): array => array_values(array_filter(array_map(
                static fn (int $id): ?string => $uuids[$id] ?? null,
                $ids,
            ), static fn (?string $uuid): bool => $uuid !== null)),
            $byLesson,
        );
    }
}
