<?php

declare(strict_types=1);

namespace App\Modules\Courses\Listeners;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\CourseTeacherProfile;
use App\Modules\Marketplace\Events\TeacherApplicationSubmitted;
use App\Shared\Scopes\WorkspaceScope;

/**
 * الكورساتُ التي سبقَتِ الملفَّ تُطالِبُ بصاحبِها لحظةَ ميلادِه.
 *
 * ⚠️ **هذا هو الفرعُ الشائعُ لا النادر، وبدونِه يعودُ العطلُ كما كان.** منذُ ٠٢٥
 * تُولَدُ مساحةُ المدرّسِ لحظةَ التسجيلِ وتحملُ `courses.create` من يومِها، بينما
 * لا يُخلَقُ `TeacherProfile` إلّا في {@see SubmitTeacherApplication}. فمدرّسٌ
 * يؤلّفُ كورساً قبلَ أن يُرسِلَ طلبَه — وهو الترتيبُ الطبيعيُّ — يكتبُ
 * `teacher_profile_id = NULL` **حتّى مع إصلاحِ `CreateCourse`**، ولا شيءَ بعدَها
 * يعودُ إليه: الكورسُ لا يُسعَّرُ أبداً، وتقولُ الشاشتانِ «لا تسعير متاح».
 *
 * ⚠️ ومستمِعٌ لا كتابةٌ من `Marketplace`: عبورُ حدِّ الوحدةِ يمرُّ بحدَثٍ
 * (الدستور III)، والحدَثُ موجودٌ أصلاً منذُ ٠٠١.
 *
 * ⚠️ ويُطالِبُ بـ`NULL` وحدَها. صفٌّ يحملُ ملفّاً آخرَ هو كورسُ مدرّسٍ آخرَ في
 * الأكاديميّةِ نفسِها، وإعادةُ إسنادِه تنقلُ سعرَ كورسِ زميلِه إلى سعرِ هذا
 * المتقدّم — تسعيرٌ يتغيّرُ بلا قرارٍ من أحد.
 */
class ClaimCoursesForNewTeacherProfile
{
    public function handle(TeacherApplicationSubmitted $event): void
    {
        $profileId = $event->application->teacher_profile_id;
        $workspaceId = (int) $event->application->workspace_id;

        if ($profileId === null) {
            return;
        }

        /*
        | ⚠️ التخطّي ثمّ المساحةُ في الشرطِ صراحةً: يعملُ هذا داخلَ معاملةِ
        | الإرسالِ حيثُ السياقُ سياقُ المتقدِّمِ عادةً — لكنّ «عادةً» ليست حارساً،
        | والنطاقُ صامتٌ تماماً حينَ يكونُ السياقُ فارغاً.
        |
        | والقاعدةُ تُعادُ لا تُختصَر: `CourseTeacherProfile::resolve()` هي التي
        | تُقرّرُ صاحبَ الكورس، وشرطُ `created_by` وحدَه هنا هجاءٌ ثانٍ للسؤالِ
        | نفسِه يفترقُ عن الأوّلِ عندَ أوّلِ كورسٍ يُنشئُه مساعد.
        */
        Course::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereNull('teacher_profile_id')
            ->get(['id', 'workspace_id', 'created_by'])
            ->each(function (Course $course) use ($profileId, $workspaceId): void {
                $resolved = CourseTeacherProfile::resolve($workspaceId, $course->created_by);

                if ($resolved !== $profileId) {
                    return;
                }

                Course::query()
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->whereKey($course->getKey())
                    ->whereNull('teacher_profile_id')
                    ->update(['teacher_profile_id' => $profileId]);
            });
    }
}
