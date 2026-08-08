<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Exceptions\ContentLockedException;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\CourseDuration;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\PublishReadiness;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Changes what an item IS, after reporting what that costs.
 *
 * An article turned into a link keeps neither its body nor its position in the
 * denominator, and the teacher has to know both before they agree — which is why
 * {@see losses()} is separate from {@see handle()} and the editor calls it first
 * (FR-020).
 *
 * Three changes are refused outright rather than reported:
 *
 * **An item holding an uploaded file.** Deleting an asset is behind two-factor
 * authentication since 004, precisely because it destroys the teacher's own work
 * for good. A type change that dropped the file would be a back door onto that
 * decision — so the file goes first, through its own guarded route.
 *
 * **A session recording.** Its type is what the 005 listener wrote, and
 * entitlement hangs off it: a recording is watched by whoever held a seat, not
 * by whoever enrolled. Retyping it silently rewrites who may open it.
 *
 * **A published item.** Its type decides whether it sits in the progress
 * denominator and whether it gates what follows, and every enrolled student's
 * stored percentage was computed against the current answer. Unpublish, change,
 * republish — the sequence 016's draft rule exists to make available.
 */
class ChangeLessonType extends Action
{
    /**
     * What this change will discard, in the teacher's words. Empty means nothing
     * is lost.
     *
     * @return list<string>
     */
    public function losses(Lesson $lesson, LessonType $target): array
    {
        $current = LessonType::from($lesson->type);

        if ($current === $target) {
            return [];
        }

        $losses = [];

        $keepsContent = in_array('content', LessonTypeRegistry::requiredToPublish($target), true);

        if (trim((string) $lesson->content) !== '' && ! $keepsContent) {
            $losses[] = 'النصّ المكتوب';
        }

        if (trim((string) $lesson->external_url) !== '' && $target !== LessonType::Link) {
            $losses[] = 'الرابط الخارجي';
        }

        if ($lesson->reference_id !== null
            && LessonTypeRegistry::family($target) !== LessonTypeRegistry::FAMILY_REFERENCE) {
            $losses[] = 'الاختبار أو الحصة المرتبطة';
        }

        if (LessonTypeRegistry::isCompletable($current) && ! LessonTypeRegistry::isCompletable($target)) {
            $losses[] = 'احتسابه ضمن نسبة تقدّم الطلاب';
        }

        return $losses;
    }

    public function handle(Lesson $lesson, LessonType $target): Lesson
    {
        $current = LessonType::from($lesson->type);

        if ($current === $target) {
            return $lesson;
        }

        $this->assertChangeable($lesson, $target);

        DB::transaction(function () use ($lesson, $target): void {
            $keeps = LessonTypeRegistry::requiredToPublish($target);

            $lesson->forceFill([
                'type' => $target->value,
                'content' => in_array('content', $keeps, true) ? $lesson->content : null,
                'external_url' => $target === LessonType::Link ? $lesson->external_url : null,
                'reference_id' => LessonTypeRegistry::family($target) === LessonTypeRegistry::FAMILY_REFERENCE
                    ? $lesson->reference_id
                    : null,
                // A type with no file has no duration to state. Video and audio
                // get theirs back from the asset when one is uploaded.
                'duration_seconds' => LessonTypeRegistry::assetKind($target)?->hasDuration() === true
                    ? $lesson->duration_seconds
                    : 0,
            ])->save();

            if ($lesson->course !== null) {
                $lesson->course->increment('structure_version');
                CourseDuration::recompute($lesson->course);
            }
        });

        return $lesson->refresh();
    }

    private function assertChangeable(Lesson $lesson, LessonType $target): void
    {
        if (! LessonTypeRegistry::isImplemented($target)) {
            throw new DomainException(
                'الواجبات لم تُفعَّل بعد — تصل مع بنك الأسئلة. اختر نوعاً آخر لهذا العنصر.',
            );
        }

        if ($lesson->class_session_id !== null) {
            throw new DomainException(
                'هذا العنصر تسجيل حصة، ونوعه هو ما يحدّد أن مَن حجز مقعداً فيها هو من يشاهده. لا يمكن تغييره.',
            );
        }

        if ($lesson->status === ContentStatus::Published) {
            throw new DomainException(
                'لا يمكن تغيير نوع عنصر منشور — نوعه داخل في نسبة تقدّم طلابك الآن. ألغِ نشره، غيّر النوع، ثم انشره من جديد.',
            );
        }

        if (PublishReadiness::hasPrimaryAsset($lesson)) {
            throw new ContentLockedException(
                'هذا العنصر يحمل ملفاً مرفوعاً. احذف الملف أولاً من صفحة العنصر — حذفه يتطلّب تحقّقاً بخطوتين — قبل تغيير النوع.',
            );
        }
    }
}
