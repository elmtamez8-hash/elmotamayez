<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\AvailabilityRules;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Replace a teacher's whole weekly availability.
 *
 * Full replacement, not a merge: a teacher editing their week expects the result
 * to be what they submitted, and merging leaves deleted windows bookable.
 *
 * The overlap rule is enforced here, not only in validation, because both doors
 * arrive through this Action (Constitution II) — the wizard's step four and
 * «مواعيدي الأسبوعيّة».
 *
 * ⚠️ ولا بابَ ثالثٌ في `‎/admin`: قِيسَ في ٢٠٢٦-٠٩-٠٩، لا حقلَ مواعيدَ في اللوحةِ
 * إطلاقاً. كانَ هذا الموضعُ يقولُ «والوحةُ تمرُّ من هنا أيضاً» — وصفٌ لمستدعٍ لا
 * وجودَ له، وهو ما يُنهي النقاشَ دونَ أن يحسمَه.
 */
class SetAvailability extends Action
{
    /** @param list<array{day_of_week: int, start_time: string, end_time: string}> $slots */
    public function handle(TeacherProfile $teacher, array $slots): void
    {
        // قبلَ كلِّ شيءٍ آخر. العمودُ يحرسُ نفسَه في {@see AvailabilitySlot}،
        // لكنّ فحصَ التداخلِ أدناهُ ونسخةَ الخطوةِ الرابعةِ يعملانِ قبلَ النموذجِ
        // وخارجَه، وكلاهُما يُقارنُ نصّاً — فوقتٌ بشكلَينِ وقتانِ مختلفان.
        $slots = AvailabilityRules::normalise($slots);

        $this->assertNoOverlaps($slots);

        DB::transaction(function () use ($teacher, $slots): void {
            $teacher->availabilitySlots()->delete();

            foreach ($slots as $slot) {
                AvailabilitySlot::query()->create([
                    'workspace_id' => $teacher->workspace_id,
                    'teacher_profile_id' => $teacher->getKey(),
                    ...$slot,
                ]);
            }

            $this->mirrorToOpenApplication($teacher, $slots);
        });

        // "متاح الآن" is a filter on these rows, so a stale list would advertise a
        // window the teacher just deleted (SC-010).
        MarketplaceCache::flush();
    }

    /**
     * والطلبُ المفتوحُ يتحرّكُ مع الصفوف، وإلّا صارَ للأسبوعِ الواحدِ هجاءان.
     *
     * ⚠️ نسخةُ المواعيدِ في `step_4` ليستْ زينةً: المعالجُ يُعيدُ ملأَها منها
     * عندَ العودة، والمراجِعُ يقرأُ منها وحدَها، و{@see SubmitTeacherApplication}
     * يُعيدُ كتابةَ الصفوفِ منها عندَ الإرسال. فمدرّسٌ في «مطلوب تعديل» عدّلَ
     * أسبوعَه من شاشةِ «مواعيدي» ثمّ أعادَ الإرسال، كانَ تعديلُه يُدهَسُ بصمتٍ
     * بقيمٍ أقدمَ منه — وفي الطريقِ كانَ المعالجُ يعرضُ عليه ساعاتٍ لم تعدْ
     * سارية، والمراجِعُ يعتمدُ ساعاتٍ غيرَ التي يُحجَزُ بها فعلاً. ضحايا ثلاثةٌ
     * لنسخةٍ واحدة، وسطرُ الكتابةِ هذا يُغلِقُها جميعاً.
     *
     * ⚠️ و`isEditable()` وحدَها هي الشرط: طلبٌ أُرسِلَ أو اعتُمِدَ هو سجلُّ ما
     * قرّرَ عليه المراجِعُ، وتحريكُه من شاشةِ المدرّسِ إعادةُ كتابةٍ لتاريخِ
     * مراجعةٍ انتهتْ — وهو ما يمنعُه {@see SaveTeacherApplicationStep} من بابِه.
     *
     * ⚠️ ولا `putStep()`: تلكَ تُحرّكُ `current_step` أيضاً، أي موضعَ المعالجِ
     * الذي وصلَ إليه صاحبُه — لسببٍ لا علاقةَ له بشاشةِ المواعيد.
     *
     * ⚠️ ويمرُّ هذا من داخلِ الإرسالِ نفسِه (الحالةُ ما زالتْ قابلةً للتعديلِ
     * حينَها)، فيكتبُ القيمَ عينَها التي قرأَها — والنسخةُ الخارجيّةُ من الطلبِ
     * تحفظُ بعدَه أعمدتَها المتّسخةَ وحدَها، فلا تدهسُ ما كُتِبَ هنا.
     *
     * @param  list<array{day_of_week: int, start_time: string, end_time: string}>  $slots
     */
    private function mirrorToOpenApplication(TeacherProfile $teacher, array $slots): void
    {
        $application = TeacherApplication::query()
            // نفسُ التجاوزِ المقصودِ في {@see TeacherApplicationController}:
            // `user_id` هو الحارس، والسياقُ قد يكونُ غيرَ سياقِ الطلب.
            ->withoutWorkspaceScope()
            ->where('user_id', $teacher->user_id)
            ->first();

        if ($application === null || ! $application->isEditable()) {
            return;
        }

        $application->step_data = [
            ...($application->step_data ?? []),
            'step_4' => [...$application->step(4), 'availability' => $slots],
        ];

        $application->save();
    }

    /** @param list<array{day_of_week: int, start_time: string, end_time: string}> $slots */
    private function assertNoOverlaps(array $slots): void
    {
        foreach ($slots as $slot) {
            if ($slot['end_time'] <= $slot['start_time']) {
                throw new DomainException('وقت النهاية يجب أن يكون بعد وقت البداية.');
            }
        }

        $byDay = [];

        foreach ($slots as $slot) {
            $byDay[$slot['day_of_week']][] = $slot;
        }

        foreach ($byDay as $daySlots) {
            usort($daySlots, fn (array $a, array $b) => $a['start_time'] <=> $b['start_time']);

            for ($i = 1; $i < count($daySlots); $i++) {
                // Touching windows (10:00–12:00 then 12:00–14:00) are fine; the
                // comparison is strict so only a genuine overlap is rejected.
                if ($daySlots[$i]['start_time'] < $daySlots[$i - 1]['end_time']) {
                    throw new DomainException('لا يمكن أن تتداخل فترتان في اليوم نفسه.');
                }
            }
        }
    }
}
