<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * تصحيحُ بياناتِ ملفِّ مدرّسٍ من فريقِ المراجعة.
 *
 * ⚠️ الخطُّ الفاصلُ هو: **ما يكتبُه المعالجُ يُعدَّلُ هنا، وما يكتبُه قرارٌ لا
 * يُعدَّل.** فحقولُ هذا الإجراءِ هي بعينِها التي يكتبُها
 * {@see SubmitTeacherApplication} من الخطوةِ الثانية — لا أكثر. و`approval_status`
 * و`is_publicly_listed` **كلاهما في `$fillable`**، فنموذجٌ يمرِّرُ مصفوفتَه خاماً
 * إلى `update()` يكتبُهما بلا صوت: يتخطّى الاشتقاقَ (معتمَد × مشاركةُ المساحة)
 * ويتخطّى ختمَ المشاركةِ والإشعارَ في {@see ApproveTeacherApplication}، ويظهرُ
 * صحيحاً في الجدول. لذا القائمةُ هنا بيضاءُ صريحةٌ لا حذفٌ من مصفوفةٍ واردة.
 *
 * ⚠️ و`hourly_rate` و`currency` مستثنيان عمداً: سعرُ المدرّسِ مدخلُه هو، ومسارُ
 * تغييرِه طلبُ تعديلِ سعرٍ في مواصفةِ ٠١٤ لا حقلٌ في لوحةِ الإدارة.
 * و`is_verified` قرارٌ لا بيان.
 */
class UpdateTeacherProfile extends Action
{
    /**
     * @param  array{slug?: string|null, headline?: string|null, bio?: string|null, qualifications?: array<int, string>|null, faqs?: array<int, array{question: string, answer: string}>|null, intro_video_url?: string|null, years_experience?: int|null, teaching_languages?: array<int, string>|null}  $attributes
     * @param  list<int>|null  $subjectIds
     * @param  list<int>|null  $gradeLevelIds
     */
    public function handle(
        TeacherProfile $profile,
        array $attributes,
        ?array $subjectIds = null,
        ?array $gradeLevelIds = null,
    ): TeacherProfile {
        $editable = array_intersect_key($attributes, array_flip([
            'slug',
            'headline',
            'bio',
            'qualifications',
            'faqs',
            'intro_video_url',
            'years_experience',
            'teaching_languages',
        ]));

        return DB::transaction(function () use ($profile, $editable, $subjectIds, $gradeLevelIds): TeacherProfile {
            $profile->forceFill($editable)->save();

            // `null` يعني «لم يُرسَلْ»، والمصفوفةُ الفارغةُ تعني «لا شيء» — وهما
            // مختلفتان: `sync([])` على قيمةٍ غائبةٍ يمسحُ تخصّصَ المدرّسِ كلَّه.
            if ($subjectIds !== null) {
                $profile->subjects()->sync($subjectIds);
            }

            if ($gradeLevelIds !== null) {
                $profile->gradeLevels()->sync($gradeLevelIds);
            }

            // بطاقةُ المدرّسِ مخزَّنةٌ داخلَ حمولاتِ الصفحةِ الأولى والقوائم، وهي
            // تحملُ الاسمَ والسطرَ التعريفيَّ والموادَّ والعنوان — فبلا الإبطالِ
            // يبقى التصحيحُ غيرَ مرئيٍّ حتّى انتهاءِ الذاكرةِ من تلقاءِ نفسِها.
            MarketplaceCache::flush();

            return $profile;
        });
    }
}
