<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Support\Facades\DB;

/**
 * «متى يظهر هذا العنصر» من جهةِ الكتابةِ والعرض — لا من جهةِ الحكم.
 *
 * الحكمُ («أمخفيٌّ هو الآن؟») في {@see LessonAudience} وحدَه؛ وهذا الصنفُ
 * يترجمُ بينَ `lessons.release_session_id` ومعرّفِ الحصّةِ الذي تتكلّمُه
 * الحمولات، لا أكثر — فالمحرّرُ يحتاجُ أن يقرأَ الربطَ وأن يكتبَه.
 *
 * ⚠️ **والقراءةُ من `ClassSession` مباشرةً لا عبرَ عقد.** هذه الوحدةُ تستوردُ
 * النموذجَ نفسَه منذُ {@see ReferenceSummary} — درسُ «حصّةٍ حيّة» يصفُ حصّتَه
 * بالعنوانِ والموعد — وعقدٌ ثالثٌ لسؤالِ «ما معرّفُ هذا الصفّ» بيتٌ ثانٍ
 * لسؤالٍ واحد.
 *
 * ⚠️ **و`withoutWorkspaceScope()` في الاتّجاهَين**: قارئُ الحمولةِ مدرّسٌ
 * سياقُه مساحتُه، وكاتبُها كذلك — لكنّ `WorkspaceContext::id()` يرجعُ إلى
 * `users.last_workspace_id`، فمدرّسٌ بدّلَ مساحتَه يقرأُ ربطاً فارغاً عن درسٍ
 * مربوطٍ فعلاً، فيظنُّه «يظهر الآن» ويحفظُه كذلك.
 */
final class LessonRelease
{
    /**
     * معرّفاتُ حصصِ الإفراجِ للشجرةِ كلِّها: **معرّفُ الدرسِ ⇒ uuid الحصّة**.
     *
     * استعلامٌ واحدٌ للشجرة، لا واحدٌ لكلِّ صفّ — والمورِدُ يعملُ مرّةً لكلِّ
     * صفٍّ بالبناء.
     *
     * @param  iterable<Lesson>  $lessons
     * @return array<int, string>
     */
    public static function uuidsAmong(iterable $lessons): array
    {
        /** @var array<int, int> $sessionIdByLesson */
        $sessionIdByLesson = [];

        foreach ($lessons as $lesson) {
            if ($lesson->release_session_id !== null) {
                $sessionIdByLesson[(int) $lesson->getKey()] = (int) $lesson->release_session_id;
            }
        }

        if ($sessionIdByLesson === []) {
            return [];
        }

        /** @var array<int, string> $uuidById */
        $uuidById = [];

        foreach (DB::table('class_sessions')
            ->whereIn('id', array_values(array_unique($sessionIdByLesson)))
            ->get(['id', 'uuid']) as $row) {
            $uuidById[(int) $row->id] = (string) $row->uuid;
        }

        $out = [];

        foreach ($sessionIdByLesson as $lessonId => $sessionId) {
            if (isset($uuidById[$sessionId])) {
                $out[$lessonId] = $uuidById[$sessionId];
            }
        }

        return $out;
    }

    /**
     * معرّفُ الحصّةِ الداخليُّ لـuuid **من كورسِ هذا الدرسِ وحدَه**.
     *
     * ⛔ **والكورسُ جزءٌ من السؤال.** بدونَه يربطُ مدرّسٌ عنصرَه بحصّةِ مدرّسٍ
     * آخرَ لم تُسلَّمْ قطُّ، فيختفي العنصرُ عن كلِّ طلابِه إلى الأبدِ بلا
     * سببٍ يظهرُ على أيِّ شاشة. ويُجابُ `null` للمجهولِ وللغريبِ بجوابٍ واحد،
     * فلا يصيرُ الفرقُ بينَهما عرّافاً يقولُ أيُّ المعرّفاتِ حقيقيّة.
     */
    public static function resolveSessionId(string $uuid, int $courseId): ?int
    {
        $id = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->where('course_id', $courseId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
