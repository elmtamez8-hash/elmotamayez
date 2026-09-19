<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Support\LessonGate;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «هل يُخفى هذا العنصرُ عن هذا القارئ، ولماذا؟» — في موضعٍ واحد.
 *
 * ⛔ **الأبوابُ أربعةٌ والحكمُ واحد.** محتوى الدرسِ يُبلَغُ من أربعةِ أبوابٍ لا
 * بابٍ واحد: {@see LessonGate} بصيغتَيه،
 * و`IssuePlaybackGrant::mayWatch()`/`mayWatchMany()` (وهو البابُ الذي يخدُمُ
 * الملفَّ فعلاً)، و`StartAttempt::guardSessionContent()`، وفهرسُ الاختباراتِ
 * وبِركةُ التدريب. وحكمٌ مكتوبٌ في أربعةِ مواضعَ هو «بابانِ يختلفان»: في ٠١٨
 * قالَ `mayWatch()` نعم وقالَ التسلسلُ لا، فصارَ تسجيلٌ **مدفوعٌ** غيرَ قابلٍ
 * للفتحِ إطلاقاً. فهذا الصنفُ يُسأَلُ من الأربعةِ جميعاً.
 *
 * ⛔ **وكلُّ قراءةٍ هنا تتجاوزُ `WorkspaceScope` بالبناء، لا بالتذكُّر.**
 * `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id`، وهو مطبوعٌ على
 * كلِّ طالبٍ أُضيفَ يوماً إلى مساحةِ عملٍ — فقراءةٌ مُنطَقةٌ هنا تُرجِعُ صفوفَ
 * نطاقٍ أقلَّ لطالبٍ مختومٍ بمساحةٍ أخرى، أي **حكماً يختلفُ باختلافِ القارئ**،
 * ولا تجهيزةَ بمساحةِ عملٍ واحدةٍ تراه. فصفوفُ النطاقِ بـ`DB::table`،
 * والمجموعاتُ من `CohortDirectory` (يُعلِنُ التجاوزَ بنفسِه)، وحالُ الحصصِ من
 * `SessionAttendanceDirectory` (كذلك).
 *
 * ⚠️ **والتكلفةُ ثابتةٌ مهما كَبُرَت الشجرة**: أربعةُ استعلاماتٍ في أسوأِ
 * الحالات، وواحدٌ في الشجرةِ التي لا نطاقَ فيها ولا موعد — وهو الحالُ اليومَ
 * على كلِّ كورسٍ على المنصّة. `CurriculumQueryBudgetTest` يُسقِطُ ما ينمو.
 */
final class LessonAudience
{
    /**
     * العنصرُ مقصورٌ على مجموعاتٍ ليسَ القارئُ في واحدةٍ منها.
     *
     * **يُسقَطُ الصفُّ ولا يُوصَفُ**: «هذا لمجموعةٍ أخرى» يقولُ لطالبٍ إنّ
     * هناكَ شيئاً لا يخصُّه، وهو ما لم يكنْ ليعرفَه — ولا فعلَ له يفتحُه، فليسَ
     * قفلاً بل غياب.
     */
    public const OUT_OF_SCOPE = 'out_of_scope';

    /**
     * العنصرُ مربوطٌ بحصّةٍ لم تُعقَدْ بعدُ ولم تُلغَ.
     *
     * والمُفرَجُ عنه `delivered_at` أو الإلغاء: حصّةٌ أُلغيَت لن تأتيَ، فحجبُ
     * ملفّاتِها إلى الأبدِ عقوبةٌ على قرارِ المدرّس (FR-008).
     */
    public const UNRELEASED = 'unreleased';

    /**
     * الحصّةُ عُقِدَت، والقارئُ لم يكنْ فيها ولم يدفعْ ثمنَها (٠٣٦).
     *
     * ⛔ **رمزٌ ثانٍ لأنّ السببَ ثانٍ، ولو كانَ الأثرُ واحداً.** كلاهما يُسقِطُ
     * الصفَّ بلا جملة، لكنّ «لم تُعقَدْ بعد» ينقضي بمرورِ الوقتِ و«لم تكنْ فيها»
     * لا ينقضي إلّا بمقعد — وقارئٌ يجدُ `unreleased` على حصّةٍ سُلِّمَت الشهرَ
     * الماضيَ يطاردُ عطباً في التوقيت.
     */
    public const NOT_MY_SESSION = 'not_my_session';

    /**
     * الحكمُ لكلِّ عنصرٍ في الشجرة: **معرّفُ الدرسِ ⇒ رمزُ الإخفاءِ أو `null`**.
     *
     * @param  iterable<Lesson>  $lessons
     * @return array<int, string|null>
     */
    public static function hiddenAmong(User $viewer, iterable $lessons): array
    {
        /** @var array<int, Lesson> $items */
        $items = [];

        foreach ($lessons as $lesson) {
            $items[(int) $lesson->getKey()] = $lesson;
        }

        if ($items === []) {
            return [];
        }

        /** @var array<int, string|null> $out */
        $out = array_fill_keys(array_keys($items), null);

        self::applyScopes($viewer, $items, $out);
        self::applyRelease($viewer, $items, $out);

        return self::exemptAuthor($viewer, $items, $out);
    }

    /**
     * الحكمُ نفسُه لعنصرٍ واحد — **مشتقٌّ من الجماعيِّ لا مكتوبٌ ثانية**، فلا
     * تهجئتانِ لسؤالٍ واحد.
     */
    public static function hiddenFor(User $viewer, Lesson $lesson): ?string
    {
        return self::hiddenAmong($viewer, [$lesson])[(int) $lesson->getKey()] ?? null;
    }

    /**
     * الدروسُ المخفيّةُ عن هذا القارئِ من بينِ ما يُسمّيه هذا الاستعلام.
     *
     * ⚠️ **للقوائمِ التي تُبنى بالاستعلامِ لا بالصفِّ الواحد** — فهرسُ
     * الاختباراتِ وبِركةُ التدريب. والجوابُ صفوفٌ لا معرّفاتٌ، لأنّ قارئاً
     * يريدُ `id` وآخرَ يريدُ `reference_id`.
     *
     * @param  Builder<Lesson>  $lessons
     * @return Collection<int, Lesson>
     */
    public static function hiddenLessons(User $viewer, Builder $lessons): Collection
    {
        $candidates = self::couldBeHidden($lessons)->get([
            'lessons.id',
            'lessons.workspace_id',
            'lessons.release_session_id',
            'lessons.reference_id',
        ]);

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $hidden = self::hiddenAmong($viewer, $candidates);

        return $candidates
            ->filter(static fn (Lesson $lesson): bool => ($hidden[(int) $lesson->getKey()] ?? null) !== null)
            ->values();
    }

    /**
     * ما **يمكنُ** أن يُخفى: الصفوفُ التي يسألُ عنها المحورانِ تحت.
     *
     * ⛔ **وهذا تضييقُ تكلفةٍ لا حكمٌ ثانٍ، ومكانُه هنا لذلك.** درسٌ بلا صفِّ
     * نطاقٍ وبلا ربطِ حصّةٍ لا يُخفى أبداً، فتحميلُ كلِّ دروسِ البنكِ لبِركةٍ
     * تُسأَلُ لكلِّ سؤالٍ يُقدَّمُ ثمنٌ بلا مقابل.
     *
     * ⚠️ **ومَن أضافَ محوراً ثالثاً إلى {@see applyScopes} أو {@see applyRelease}
     * يُضيفُه هنا في التغييرِ نفسِه** — وإلّا صارَ المحورُ الجديدُ صامتاً في
     * القوائمِ وحدَها، وهو أسوأُ من ألّا يكونَ.
     *
     * @param  Builder<Lesson>  $lessons
     * @return Builder<Lesson>
     */
    private static function couldBeHidden(Builder $lessons): Builder
    {
        return $lessons->where(fn (Builder $query): Builder => $query
            ->whereNotNull('lessons.release_session_id')
            ->orWhereExists(fn ($exists) => $exists
                ->selectRaw('1')
                ->from('lesson_cohort_scopes')
                ->whereColumn('lesson_cohort_scopes.lesson_id', 'lessons.id')));
    }

    /**
     * المحورُ الأوّل — «لمن هذا العنصر».
     *
     * ⚠️ **ولا يُسأَلُ عن مجموعاتِ القارئِ إن لم يكنْ في الشجرةِ عنصرٌ مقصور**،
     * وهو الحالُ على كلِّ كورسٍ لم يُضيَّقْ فيه شيء: استعلامٌ واحدٌ يرجعُ
     * فارغاً وينتهي الأمر.
     *
     * @param  array<int, Lesson>  $items
     * @param  array<int, string|null>  $out
     */
    private static function applyScopes(User $viewer, array $items, array &$out): void
    {
        /** @var array<int, array<int, true>> $scoped */
        $scoped = [];

        foreach (DB::table('lesson_cohort_scopes')
            ->whereIn('lesson_id', array_keys($items))
            ->get(['lesson_id', 'cohort_id']) as $row) {
            $scoped[(int) $row->lesson_id][(int) $row->cohort_id] = true;
        }

        if ($scoped === []) {
            return;
        }

        /*
        | ⚠️ **العضويّةُ المفتوحةُ الآن، لا «كانَ عضواً يوماً».**
        | `everMemberCohortIdsFor()` سؤالٌ آخرُ له بيتُه: قراءةُ خيطِ مجموعةٍ
        | قديمةٍ تبقى بعدَ النقل، أمّا «لمن هذا العنصر» فيتبعُ الطالبَ إلى
        | مجموعتِه الجديدةِ ويتركُ ما قُصِرَ على القديمة (`research.md` · ق-٦).
        */
        $mine = array_flip(app(CohortDirectory::class)->openMembershipCohortIdsFor($viewer));

        foreach ($scoped as $lessonId => $cohortIds) {
            if (array_intersect_key($cohortIds, $mine) === []) {
                $out[$lessonId] = self::OUT_OF_SCOPE;
            }
        }
    }

    /**
     * المحورُ الثاني — «متى يظهر، ولمن».
     *
     * ⛔ **والتسليمُ وحدَه لم يكنْ كافياً، وهي الفتحةُ التي أبقَت المادّةَ
     * تتدفّقُ على مَن لا يستطيعُ حجزَ حصّةٍ واحدة (٠٣٦).** الشرطُ كانَ «هل
     * عُقِدَت هذه الحصّة؟» ولم يكنْ «هل كانَ هذا الطالبُ فيها؟» — فعضوُ
     * المجموعةِ يتلقّى مادّةَ كلِّ حصّةٍ جايةٍ إلى الأبدِ ولو لم يحجزْ منها
     * واحدة. والتسجيلُ يُستحَقُّ **بالمقعد** منذُ ٠١٠ · FR-030، والمادّةُ
     * المكتوبةُ للساعةِ نفسِها هي الاستحقاقُ نفسُه بامتدادِ ملفٍّ آخر.
     *
     * ⚠️ **والملغاةُ تُفرَجُ للجميع، ولا يُسأَلُ عنها مقعد.** الإلغاءُ يردُّ
     * المقاعدَ (`SessionCancelled`)، فشرطُ المقعدِ عليها يدفنُ مادّةَ حصّةٍ لن
     * تُعقَدَ أبداً — عينُ ما كُتِبَت FR-008 لمنعِه.
     *
     * @param  array<int, Lesson>  $items
     * @param  array<int, string|null>  $out
     */
    private static function applyRelease(User $viewer, array $items, array &$out): void
    {
        $sessionIds = array_values(array_unique(array_map(
            static fn (Lesson $lesson): int => (int) $lesson->release_session_id,
            array_filter($items, static fn (Lesson $lesson): bool => $lesson->release_session_id !== null),
        )));

        if ($sessionIds === []) {
            return;
        }

        $directory = app(SessionAttendanceDirectory::class);

        // استعلامان لا أكثر: حالُ الحصص، ثمّ ما دفعَ هذا القارئُ ثمنَه منها.
        $states = $directory->releaseStatesFor($sessionIds);
        $mine = array_flip($directory->paidSessionIdsFor($viewer, $sessionIds));

        foreach ($items as $id => $lesson) {
            if ($out[$id] !== null || $lesson->release_session_id === null) {
                continue;
            }

            $sessionId = (int) $lesson->release_session_id;

            if (! isset($states[$sessionId])) {
                $out[$id] = self::UNRELEASED;

                continue;
            }

            // الملغاةُ (`false`) مُفرَجةٌ للجميعِ ولا مقعدَ فيها لأحد — انظرْ وصفَ المِنهاج.
            if ($states[$sessionId] && ! isset($mine[$sessionId])) {
                $out[$id] = self::NOT_MY_SESSION;
            }
        }
    }

    /**
     * المؤلّفُ يرى ما ألَّف — **استثناءٌ واحدٌ هنا لا أربعةٌ على الأبواب**
     * (FR-011). فمدرّسٌ لا يرى ما قَصَرَه بنفسِه لا يستطيعُ تصحيحَه.
     *
     * ⛔ **والشرطُ دورُ المحورِ لا مجرّدُ العضويّة.** قِيسَ على قاعدةٍ حقيقيّةٍ
     * في ٢٠٢٦-٠٩-٠٩: `workspace_members` تحملُ **ستّةَ صفوفٍ بدورِ `student`** —
     * فمدرّسٌ أو بذرةٌ تضعُ طالباً في مساحةِ عمل، و«عضوٌ ⇒ مؤلّف» يفتحُ
     * لأولئكَ الستّةِ كلَّ ما قُصِرَ على غيرِهم. والسؤالُ بالنفيِ
     * (`role != student`) كما في `User::teachesOnPlatform()`، فدورٌ مخصَّصٌ
     * مجهولٌ يسقطُ نحوَ **الإخفاء** لا نحوَ الفتح.
     *
     * ⚠️ **ولا يُسأَلُ إلّا إن كانَ ثمّةَ ما يُخفى** — وهو النادر.
     *
     * @param  array<int, Lesson>  $items
     * @param  array<int, string|null>  $out
     * @return array<int, string|null>
     */
    private static function exemptAuthor(User $viewer, array $items, array $out): array
    {
        $hidden = array_filter($out, static fn (?string $code): bool => $code !== null);

        if ($hidden === []) {
            return $out;
        }

        $workspaceIds = array_values(array_unique(array_map(
            static fn (int $id): int => (int) $items[$id]->workspace_id,
            array_keys($hidden),
        )));

        $teaches = array_flip(DB::table('workspace_members')
            ->where('user_id', $viewer->getKey())
            ->whereIn('workspace_id', $workspaceIds)
            ->where('role', '!=', Roles::STUDENT)
            ->pluck('workspace_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());

        if ($teaches === []) {
            return $out;
        }

        foreach (array_keys($hidden) as $id) {
            if (isset($teaches[(int) $items[$id]->workspace_id])) {
                $out[$id] = null;
            }
        }

        return $out;
    }
}
