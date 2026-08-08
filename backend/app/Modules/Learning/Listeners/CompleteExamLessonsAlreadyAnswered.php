<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Events\ExamItemOpened;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\ExamGateSatisfaction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Credits the work students had already done before the item existed.
 *
 * `CompleteExamLessonOnSubmission` covers everyone who sits the exam from now on.
 * It cannot cover anyone who sat it earlier — and an exam is answered from its own
 * page, so students routinely finish one weeks before the teacher decides where it
 * belongs in the tree. For them no `ExamSubmitted` will ever fire again, the newly
 * published item joins their denominator, and their percentage is capped below 100%
 * permanently: no `CourseCompleted`, no certificate. "Just resit it" is not an
 * answer either, since `max_attempts` may already be spent.
 *
 * The sequential gate does not have this problem — it reads the attempts live. The
 * denominator does, because it reads a stored row. This is what writes it.
 *
 * Idempotent through `MarkLessonComplete`, which returns early on a row that is
 * already complete: a teacher toggling a gate back and forth changes nothing.
 *
 * **Queued, and it has to be.** This runs when the teacher publishes an exam item,
 * and the set it walks is every student of the course who already sat that exam —
 * a number this code does not get to assume. Each one costs `MarkLessonComplete`,
 * which is a transaction plus a progress recompute; at 5,000 students that is tens
 * of thousands of statements. Synchronously it blocked the publish request until
 * the gateway killed it — and by then `PublishTreeNodes` had already committed, so
 * the item was live with an arbitrary prefix of students credited and the rest
 * capped below 100% for good. The failure mode was worse than the latency.
 */
class CompleteExamLessonsAlreadyAnswered implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly MarkLessonComplete $markComplete,
    ) {}

    public function handle(ExamItemOpened $event): void
    {
        $item = $event->lesson;

        if ($item->type !== LessonType::Exam->value
            || $item->status !== ContentStatus::Published
            || $item->reference_id === null) {
            // A draft item is not in anybody's denominator, so there is nothing
            // to credit yet — publishing it fires this again.
            return;
        }

        $studentIds = ExamGateSatisfaction::attempts($item->reference_id, $item->exam_gate ?? ExamGate::Attempt)
            ->distinct()
            ->pluck('student_user_id');

        if ($studentIds->isEmpty()) {
            return;
        }

        Enrollment::query()
            ->where('course_id', $item->course_id)
            ->whereIn('student_user_id', $studentIds)
            // Active only — the condition `accessTo` applies and the controller
            // path returns 422 over. Without it this credited an expired or
            // cancelled enrolment, `MarkLessonComplete` flipped it to `completed`,
            // `CourseCompleted` fired and a certificate issued to someone the API
            // refuses to let finish a single lesson.
            ->where('status', 'active')
            // Nobody has a completed row for this item yet, so the ones that do
            // are already done — and `MarkLessonComplete` costs a transaction each
            // to discover that. Filtering here makes a re-publish nearly free.
            ->whereDoesntHave('progress', fn ($query) => $query
                ->where('lesson_id', $item->getKey())
                ->where('status', 'completed'))
            // `with('course')`, because `MarkLessonComplete` opens with
            // `loadMissing('course')` — without it that is one SELECT of the SAME
            // course row per student.
            ->with('course')
            // Chunked by key: the set is every student of a course, which is not a
            // number this code gets to assume. `get()` held all of them in memory
            // at once.
            ->chunkById(200, function ($enrollments) use ($item): void {
                foreach ($enrollments as $enrollment) {
                    $this->markComplete->handle($enrollment, (int) $item->getKey());
                }
            });
    }
}
