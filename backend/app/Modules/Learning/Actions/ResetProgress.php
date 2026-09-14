<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Models\ProgressHistory;
use App\Modules\Learning\Support\CourseProgress;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * التراجعُ عن الإتمام: درسٌ واحدٌ أو الكورسُ كلُّه.
 *
 * ⚠️ **والشهادةُ لا تُمَسُّ إطلاقاً — بقرارِ المالك.** الطالبُ أنهى الكورسَ فعلاً
 * مرّةً، والشهادةُ واقعةٌ حدثت ولها تاريخُها؛ سحبُها لأنّه قرّرَ المراجعةَ يجعلُ
 * رابطَ التحقّقِ العامَّ يقولُ «غيرُ صالحة» لصاحبِ عملٍ يسألُ عنه في تلك اللحظة.
 * ولا حاجةَ لحارسٍ هنا: هذا الإجراءُ لا يلمسُ جدولَ الشهاداتِ بسطر.
 *
 * ⚠️ **ولا شهادةَ ثانيةً عندَ الإتمامِ من جديد، وذلك مضمونٌ في ثلاثِ طبقات** —
 * قِيسَ لا افتُرِض: فهرسٌ فريدٌ على `(workspace_id, enrollment_id, course_id)`،
 * و{@see IssueCertificate} يعودُ عندَ وجودِ صفٍّ **قبلَ** سطرِ `event()`، و
 * `CertificateIssued` مستمعُه الوحيدُ هو الإشعار. فإعادةُ الإتمامِ لا تُنتِجُ
 * صفّاً ولا حدثاً ولا إشعاراً.
 *
 * ⚠️ **تحديثٌ لا حذف.** `progress_history.lesson_progress_id` فهرسٌ **بلا مفتاحٍ
 * أجنبيّ** (قِيسَ في هجرةِ ٠٧-٢٠)، فحذفُ صفِّ التقدّمِ يُيتِّمُ سجلَّ تدقيقِه في
 * صمتٍ بدلَ أن يمنعَه شيء — وسجلُّ «متى أتمَّ ومتى تراجع» هو نصفُ قيمةِ هذه
 * الميزة. والصفُّ يعودُ `in_progress` لأنّ `CourseProgress::completed()` يعُدُّ
 * `status = 'completed'` وحدَه.
 *
 * ⛔ **وعنصرُ الاختبارِ لا يُمَسُّ — وهذا حارسٌ ضدَّ أسوأِ عطبٍ في هذا المستودع.**
 * صفُّ تقدّمِ الاختبارِ يكتبُه {@see CompleteExamLessonOnSubmission} عندَ تسليمِ
 * الورقةِ لا زرٌّ للطالب، و`StartAttempt` يرفضُ محاولةً بعدَ `exams.max_attempts`
 * (قِيسَ: السطر ١٦٦). فرفعُ ذلك الإتمامِ عن طالبٍ استنفدَ محاولاتِه يضعُ في
 * المقامِ عنصراً **لا يمكنُ إتمامُه أبداً** — أي طالبٌ تحتَ ١٠٠٪ للأبد، وهي
 * العائلةُ نفسُها التي تُبقي الكورسَ بلا حدثِ إتمام. والقاعدةُ تُقرَأُ من
 * `LessonTypeRegistry::isSelfCompletable()` — نفسِ المحمولِ الذي يقرؤه بابُ
 * الإتمام — لا من `type === 'exam'`: نوعٌ جديدٌ يُكمِلُه مستمعٌ لا الطالبُ يقعُ
 * في الحفرةِ نفسِها بلا سطرٍ هنا. ومعنى المنتَجِ يوافقُ: التراجعُ يردُّ ما أعلنَه
 * الطالبُ بيدِه، ونتيجةُ ورقةٍ صُحِّحَت ليست منه.
 *
 * ⚠️ **والمعامِلُ الثاني بلا قيمةٍ افتراضيّة، عمداً.** `?int $lessonId = null`
 * تجعلُ النداءَ الناقصَ يمحو **الكورسَ كلَّه** بصمت — أخطرُ الاحتمالَينِ يقعُ عندَ
 * السهو. بلا افتراضيٍّ يُجبَرُ كلُّ مُنادٍ على تسميةِ النطاقِ الذي يقصدُه.
 */
class ResetProgress extends Action
{
    /**
     * @param  int|null  $lessonId  درسٌ بعينِه، أو `null` للكورسِ كلِّه
     * @return int عددُ الدروسِ التي رجعَت
     */
    public function handle(Enrollment $enrollment, ?int $lessonId): int
    {
        $enrollment->loadMissing('course');

        return DB::transaction(function () use ($enrollment, $lessonId): int {
            $rows = LessonProgress::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('status', 'completed')
                ->whereHas('lesson', fn ($query) => $query->whereIn('type', self::selfCompletableTypes()))
                ->when($lessonId !== null, fn ($query) => $query->where('lesson_id', $lessonId))
                ->get();

            foreach ($rows as $row) {
                $row->update(['status' => 'in_progress', 'completed_at' => null]);

                ProgressHistory::create([
                    'workspace_id' => $row->workspace_id,
                    'lesson_progress_id' => $row->getKey(),
                    'event' => 'reset',
                    'payload' => [
                        'reset_at' => now()->toIso8601String(),
                        // نطاقُ الفعلِ مكتوبٌ في السجلّ: صفٌّ واحدٌ لا يقولُ من نفسِه
                        // أكانَ تراجعاً عن درسٍ أم جزءاً من إعادةِ كورسٍ كامل.
                        'scope' => $lessonId === null ? 'course' : 'lesson',
                    ],
                ]);
            }

            /*
            | ⚠️ **`revertCompletion` لا `sync` وحدَها.** `sync()` تمشي في اتّجاهٍ
            | واحدٍ عمداً — تضعُ `completed` ولا ترفعُه — لأنّ FR-050 وFR-051 تقولانِ
            | إنّ محتوىً يُضافُ بعدَ إنهاءِ طالبٍ يُنقِصُ الرقمَ **ويتركُ الواقعة**،
            | و{@see ResyncCourseProgress} ينادِيها بعدَ كلِّ نشر. والتراجعُ حالةٌ
            | أخرى: صاحبُه طلبَه، فبقاءُ «مكتمل» فوقَ «‏٠٪» صفٌّ يقولُ شيئَينِ
            | متناقضَين. والدالّةُ الثانيةُ تنادي الأولى بداخلِها، فالحسابُ واحد.
            */
            CourseProgress::revertCompletion($enrollment);

            return $rows->count();
        });
    }

    /**
     * الأنواعُ التي يُعلنُ الطالبُ إتمامَها بنفسِه — وهي وحدَها ما يُتراجَعُ عنه.
     *
     * @return list<string>
     */
    public static function selfCompletableTypes(): array
    {
        return array_values(array_map(
            static fn (LessonType $type): string => $type->value,
            array_filter(
                LessonType::cases(),
                static fn (LessonType $type): bool => LessonTypeRegistry::isSelfCompletable($type),
            ),
        ));
    }
}
