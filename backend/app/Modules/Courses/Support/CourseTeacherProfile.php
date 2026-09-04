<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Scopes\WorkspaceScope;

/**
 * مَن يُسعَّرُ هذا الكورسُ بسعرِه — وهو العمودُ الذي لم يكن يكتبُه أحد.
 *
 * ⛔ **العطلُ الذي وُجِدَ هذا الصنفُ لأجلِه**: `courses.teacher_profile_id` قابلٌ
 * للإسنادِ منذُ ٠٠٦، وتعليقُه على النموذجِ يقولُ إنّه «الذي بدونِه لا يعملُ بحثُ
 * السعرِ المعتمَدِ إطلاقاً» — و**لا سطرَ في الشجرةِ كلِّها كان يكتبُه**. لا
 * `CreateCourse` ولا طلبٌ ولا بذرةُ إنتاج. فكلُّ كورسٍ أنشأه المنتَجُ وُلِدَ
 * بـ`NULL`، و`EloquentApprovedRateDirectory::lookUp()` يعودُ من أوّلِ سطرٍ فيه،
 * فـ`CostPlusPricing::price()` تعودُ `null` لكلِّ حزمة، فـ`ListCreditPackages`
 * تعودُ بقائمةٍ فارغة — وتقرأُ الشاشتان جملةً مهذّبةً تبدو سياسةً لا عطلاً:
 * «لا تسعير متاح لهذا الكورس حاليّاً». محرّكُ الفوترةِ كلُّه (٠٠٦ · ٠٢٤) لم
 * يستطعْ تسعيرَ كورسٍ واحدٍ حقيقيٍّ قطّ، بلا خطأٍ في أيِّ سجلّ.
 * قِيسَ على الإنتاجِ 2026-09-04: سبعةُ كورساتٍ، `teacher_profile_id` فارغٌ في
 * سبعتِها.
 *
 * ⚠️ **والقيدُ على المساحةِ حاملٌ في الفرعَين**: إنسانٌ يملكُ مساحتَه ويساعدُ في
 * مساحةِ غيرِه (٠٢٥)، فملفٌّ من مساحةٍ أخرى لا يجدُ له سعراً معتمَداً — لأنّ
 * `settlement_rates` تُقرأُ داخلَ `forWorkspace` — فيعودُ `null` مرّةً أخرى، وهو
 * الصمتُ نفسُه من بابٍ ثانٍ.
 *
 * ⚠️ **والفراغُ حالةٌ مشروعةٌ لا خطأ**: مدرّسٌ لم يُرسِلْ طلبَه بعدُ لا ملفَّ له
 * أصلاً — والمساحةُ تُولَدُ مع التسجيلِ وتحملُ `courses.create` من يومِها (٠٢٥)،
 * فالكورسُ قبلَ الطلبِ هو الحالةُ الشائعةُ لا النادرة. لذا يُكتَبُ `null` بهدوء،
 * ويطالِبُ {@see ClaimCoursesForNewTeacherProfile} بها لحظةَ ميلادِ الملفّ.
 */
final class CourseTeacherProfile
{
    /**
     * صاحبُ الكورسِ: مُنشِئُه إن كان له ملفٌّ في هذه المساحة، وإلّا مالكُها.
     *
     * ⚠️ الترتيبُ مقصود. المساعدُ يُنشئُ كورساً في مساحةِ مدرّسٍ آخر، فسعرُ
     * الكورسِ سعرُ **صاحبِ المساحة** لا سعرُ من ضغطَ الزرّ — ولا ملفَّ للمساعدِ
     * هناكَ أصلاً في الحالةِ الشائعة، فالفرعُ الأوّلُ لا يلتقطُه.
     */
    public static function resolve(int $workspaceId, ?int $creatorUserId): ?int
    {
        $profileId = $creatorUserId === null ? null : self::profileIn($workspaceId, $creatorUserId);

        if ($profileId !== null) {
            return $profileId;
        }

        $ownerId = Workspace::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereKey($workspaceId)
            ->value('owner_user_id');

        return $ownerId === null ? null : self::profileIn($workspaceId, (int) $ownerId);
    }

    /**
     * ⚠️ `withoutGlobalScope`: تُنادى من هجرةٍ لا سياقَ لها، ومن مستمِعٍ في
     * طابور. والنطاقُ صامتٌ حينَ يكونُ السياقُ فارغاً — فلا يحرسُ شيئاً هنا
     * ويكذبُ حينَ يكونُ السياقُ مساحةً أخرى. والمساحةُ في الشرطِ صراحةً.
     */
    private static function profileIn(int $workspaceId, int $userId): ?int
    {
        $id = TeacherProfile::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
