<?php

declare(strict_types=1);

use App\Modules\Courses\Support\CourseTeacherProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ردمُ `courses.teacher_profile_id` — العمودُ الذي لم يكتبْه أحدٌ منذُ ٠٠٦.
 *
 * ⛔ قِيسَ على الإنتاجِ 2026-09-04: **سبعةُ كورسات، العمودُ فارغٌ في سبعتِها.**
 * وهو الذي يبدأُ منه `EloquentApprovedRateDirectory::lookUp()`، فبلا قيمةٍ فيه
 * تعودُ كلُّ حزمةٍ بلا سعر، وتعودُ `ListCreditPackages` بقائمةٍ فارغة، وتقرأُ
 * شاشةُ الطالبِ وشاشةُ موظّفِ المنصّةِ جملةً مهذّبةً تبدو سياسةً لا عطلاً. محرّكُ
 * الفوترةِ لم يُسعِّرْ كورساً حقيقيّاً واحداً قطّ، بلا سطرِ خطأٍ في أيِّ سجلّ.
 *
 * ⚠️ **يمرُّ بالقاعدةِ نفسِها لا باستعلامٍ يشبهُها.**
 * {@see CourseTeacherProfile::resolve()} هي ما ينادِيه `CreateCourse` والمستمِعُ
 * كذلك — وهجاءٌ ثالثٌ هنا يفترقُ عنهما عندَ أوّلِ كورسٍ يُنشئُه مساعد. سابقتُه
 * ردمُ ٠٢٥: مرَّ بـ`CreateWorkspace` لا بإدراجٍ مباشر.
 *
 * ⚠️ **ويتخطّى ما لا يُحَلّ ولا يرمي.** الفراغُ حالةٌ مشروعةٌ يجيبُ عنها المنتَجُ
 * بصدق: كورسٌ في مساحةٍ لا ملفَّ مدرّسٍ فيها لا سعرَ له فعلاً. والرميُ يوقفُ
 * النشرةَ على صفوفِ عرضٍ لا تخصُّ أحداً — قِيسَ منها اثنانِ على الإنتاج.
 *
 * ⚠️ ويعودُ بهدوءٍ حينَ لا مرشَّحَ: `RefreshDatabase` يُعيدُ تشغيلَ كلِّ هجرةٍ في
 * كلِّ اختبارِ Feature، على قاعدةٍ لا كورسَ فيها أصلاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        $candidates = DB::table('courses')
            ->whereNull('teacher_profile_id')
            ->select('id', 'workspace_id', 'created_by')
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        // الهجراتُ ليست ملفوفةً بمعاملةٍ على MySQL ولا على SQLite
        // (`Schema\Grammars\Grammar::$transactions = false`)، فكلُّ واحدةٍ تفتحُ
        // معاملتَها.
        DB::transaction(function () use ($candidates): void {
            foreach ($candidates as $course) {
                $profileId = CourseTeacherProfile::resolve(
                    (int) $course->workspace_id,
                    $course->created_by === null ? null : (int) $course->created_by,
                );

                if ($profileId === null) {
                    continue;
                }

                DB::table('courses')
                    ->where('id', $course->id)
                    ->whereNull('teacher_profile_id')
                    ->update(['teacher_profile_id' => $profileId]);
            }
        });
    }

    /**
     * لا تراجُع: العمودُ كان فارغاً بعطلٍ لا بقرار، وإفراغُه ثانيةً يُعيدُ كسرَ
     * التسعيرِ في كلِّ كورسٍ على المنصّة.
     */
    public function down(): void {}
};
