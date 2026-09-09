<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\TeacherApplication;
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

            $this->mirrorToOpenApplication($profile);

            // بطاقةُ المدرّسِ مخزَّنةٌ داخلَ حمولاتِ الصفحةِ الأولى والقوائم، وهي
            // تحملُ الاسمَ والسطرَ التعريفيَّ والموادَّ والعنوان — فبلا الإبطالِ
            // يبقى التصحيحُ غيرَ مرئيٍّ حتّى انتهاءِ الذاكرةِ من تلقاءِ نفسِها.
            MarketplaceCache::flush();

            return $profile;
        });
    }

    /**
     * والطلبُ المفتوحُ يتحرّكُ مع الملفّ، وإلّا دهسَ الإرسالُ التصحيحَ الذي طُلِبَ.
     *
     * ⚠️ حقولُ هذا الإجراءِ هي حقولُ الخطوةِ الثانيةِ بعينِها — تقولُه وثيقتُه
     * أعلاه — و{@see SubmitTeacherApplication} يُعيدُ كتابةَ الملفِّ منها عندَ كلِّ
     * إرسال. فمدرّسٌ في «مطلوب تعديل» صحّحَ سيرتَه ومؤهّلاتِه وموادَّه من صفحةِ
     * ملفِّه ثمّ أعادَ الإرسال، كانَ كلُّ ذلك يعودُ إلى ما قبلَه بصمت — والمراجِعُ
     * يُفتَحُ له النصُّ الذي طلبَ تعديلَه بعينِه، فيردُّ «لم تُعدِّلْ شيئاً».
     * سبعةُ حقولٍ بدلَ حقلِ المواعيدِ الواحد، وعلى الحقولِ التي يُطلَبُ تعديلُها
     * فعلاً.
     *
     * ⚠️ ويُقرَأُ من الملفِّ **بعدَ** حفظِه ومزامنةِ تصنيفاتِه، لا من المصفوفةِ
     * الواردة: الغائبُ يعني «لم يُرسَلْ» فيبقى على قيمتِه، والتصنيفُ يصلُ
     * معرِّفاتٍ بينما الخطوةُ الثانيةُ تُخزَّنُ أسماءً — والقراءةُ من الحالةِ
     * المحفوظةِ تُعطي الأمرَينِ بلا فرعٍ لأيٍّ منهما.
     *
     * ⚠️ و`isEditable()` وحدَها هي الشرط، وهي التي تجعلُ هذا آمناً على البابِ
     * الثاني: اللوحةُ تُصحّحُ ملفَّ مدرّسٍ معتمَدٍ في الغالب، وطلبُه المعتمَدُ
     * سجلُّ مراجعةٍ انتهت فلا يتحرّك.
     *
     * ⚠️ ولا `slug` ولا `faqs` ولا `intro_video_url` هنا: ثلاثتُها حقولُ ملفٍّ
     * لا تعرفُها الخطوةُ الثانية، وكتابتُها فيها تخترعُ مفتاحاً لا قارئَ له.
     */
    private function mirrorToOpenApplication(TeacherProfile $profile): void
    {
        $application = TeacherApplication::query()
            // نفسُ التجاوزِ المقصودِ في {@see TeacherApplicationController}:
            // `user_id` هو الحارس، والسياقُ قد يكونُ سياقَ مراجِعٍ لا سياقَ الطلب.
            ->withoutWorkspaceScope()
            ->where('user_id', $profile->user_id)
            ->first();

        if ($application === null || ! $application->isEditable()) {
            return;
        }

        $application->step_data = [
            ...($application->step_data ?? []),
            'step_2' => [
                ...$application->step(2),
                'subjects' => $profile->subjects()->pluck('slug')->all(),
                'grade_levels' => $profile->gradeLevels()->pluck('slug')->all(),
                'years_experience' => (int) $profile->years_experience,
                'qualifications' => array_values($profile->qualifications ?? []),
                'teaching_languages' => array_values($profile->teaching_languages ?? []),
                'headline' => (string) $profile->headline,
                'bio' => $profile->bio,
            ],
        ];

        $application->save();
    }
}
