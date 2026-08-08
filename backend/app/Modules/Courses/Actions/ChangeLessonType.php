<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Exceptions\ContentLockedException;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\CourseDuration;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Courses\Support\PublishReadiness;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
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
    use LogsActivity;

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

        // Read from the registry like the content rule above it, not compared
        // against `Link` by name. The registry already states which types require
        // an `external_url`; a second external type added later would otherwise
        // keep its body and silently drop its URL.
        if (trim((string) $lesson->external_url) !== ''
            && ! in_array('external_url', LessonTypeRegistry::requiredToPublish($target), true)) {
            $losses[] = 'الرابط الخارجي';
        }

        // Lost on ANY change of type, including one reference type to another.
        // `reference_id` is a bare id whose meaning comes from `type` (R9), so an
        // exam id carried over to `live_session` is not the same link preserved —
        // it is the same number now read against a different table. Which is
        // exactly the ambiguity a `reference_type` column would have created, got
        // in through the back door of "keeping" the value.
        if ($lesson->reference_id !== null) {
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

        // Read BEFORE the write. Asking afterwards would compare the new type
        // against itself and record that nothing was lost — on the one action
        // whose whole point is that something was.
        $lost = $this->losses($lesson, $target);

        DB::transaction(function () use ($lesson, $target, $current, $lost): void {
            $keeps = LessonTypeRegistry::requiredToPublish($target);

            $lesson->forceFill([
                'type' => $target->value,
                'content' => in_array('content', $keeps, true) ? $lesson->content : null,
                'external_url' => in_array('external_url', $keeps, true) ? $lesson->external_url : null,
                // Always cleared — see losses(). The teacher repicks, which takes
                // one click and cannot point an item at a row in the wrong table.
                'reference_id' => null,
                'exam_gate' => $target === LessonType::Exam ? ExamGate::Attempt : null,
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

            // What was discarded is recorded with it. "type_changed" alone would
            // leave an auditor unable to answer the only question anyone asks
            // afterwards: where did the text go.
            $this->logActivity('type_changed', $lesson, [
                'from' => $current->value,
                'to' => $target->value,
                'lost' => $lost,
            ]);
        });

        return $lesson->refresh();
    }

    private function assertChangeable(Lesson $lesson, LessonType $target): void
    {
        LessonTypeRegistry::assertImplemented($target);

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
