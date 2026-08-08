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
 */
class CompleteExamLessonsAlreadyAnswered
{
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

        $enrollments = Enrollment::query()
            ->where('course_id', $item->course_id)
            ->whereIn('student_user_id', $studentIds)
            ->get();

        foreach ($enrollments as $enrollment) {
            $this->markComplete->handle($enrollment, (int) $item->getKey());
        }
    }
}
