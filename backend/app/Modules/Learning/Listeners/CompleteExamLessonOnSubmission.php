<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\ExamGateSatisfaction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ticks off the exam item when its exam is sat.
 *
 * Without this the exam type is a trap rather than a feature. It is completable,
 * so it enters the progress denominator (`LessonTypeRegistry`) — but no student
 * can ever mark one done: `POST /enrollments/{e}/lessons/{l}/complete` is the
 * only writer of a progress row, and an exam is finished from the exam's own
 * page, not from the item. So the moment a teacher placed a quiz in their tree,
 * every enrolled student's ceiling dropped below 100%, `CourseCompleted` stopped
 * firing and no certificate issued. Not slowly — never. Exactly the shape of the
 * recording bug `FR-026أ` was written for, arriving through a different door.
 *
 * It listens to `ExamSubmitted` and not to `ExamPassed`, because which of the
 * two counts is the ITEM's decision (`FR-041`): under "يكفي أن يُحاول" sitting
 * it is the work, and a failing mark completes the item while still telling the
 * student they got it wrong. Under "يجب أن ينجح" only a pass does. Listening to
 * `ExamPassed` would silently impose the strict reading on every item.
 *
 * One exam may be placed more than once in a course; each placement is its own
 * item with its own gate, so all of them are answered.
 *
 * **Queued, and after the commit.** `GradeAttempt` fires `ExamSubmitted` from
 * inside its own transaction, so running here synchronously tied the student's
 * GRADED ATTEMPT to progress bookkeeping: any throw in this listener — a history
 * insert failing, a deadlock on `enrollments` — rolled the submission back. They
 * sat the exam, it was marked, and they got a 500 with nothing stored and possibly
 * an attempt spent. Bookkeeping must not be able to destroy the thing it is
 * bookkeeping for. `ShouldHandleEventsAfterCommit` also means the job never sees a
 * transaction that was rolled back after it was dispatched.
 */
class CompleteExamLessonOnSubmission implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly MarkLessonComplete $markComplete,
    ) {}

    public function handle(ExamSubmitted $event): void
    {
        $attempt = $event->attempt;

        if ($attempt->exam_id === null || $attempt->is_practice) {
            /*
            | Revision is not coursework.
            |
            | A self-generated paper (spec 008) belongs to no exam anybody
            | authored, so there is no exam lesson for it to complete. And the
            | second half of the condition is the one that is easy to miss: the
            | SAME exam may be sat officially and again for practice, and that
            | row does carry an `exam_id` — so the null check alone lets a
            | revision run tick off the lesson, move course progress, and
            | eventually issue a certificate.
            */
            return;
        }

        $enrollment = $this->enrollmentFor($attempt->enrollment_id, $attempt->exam_id, (int) $attempt->student_user_id);

        if ($enrollment === null || ! $enrollment->isActive()) {
            // An exam sat outside any enrolment — a standalone quiz, or a teacher
            // previewing their own. There is no progress to move.
            //
            // And an expired or cancelled enrolment is refused for the same
            // reason the controller refuses it: completing a lesson there would
            // tip the enrolment to `completed` and issue a certificate to someone
            // the API will not let finish anything.
            return;
        }

        $items = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $enrollment->course_id)
            ->where('type', LessonType::Exam->value)
            ->where('reference_id', $attempt->exam_id)
            // A draft item is not work the student has been asked to do, and it
            // is not in the denominator either — completing it would credit them
            // against a total that does not include it.
            ->where('status', ContentStatus::Published)
            ->get();

        foreach ($items as $item) {
            // Read through the shared predicate rather than by inspecting this
            // attempt: the student may hold an earlier passing attempt, and a
            // failing submission today must not un-answer it.
            $met = ExamGateSatisfaction::metBy(
                (int) $attempt->exam_id,
                $item->exam_gate ?? ExamGate::Attempt,
                (int) $attempt->student_user_id,
            );

            if ($met) {
                $this->markComplete->handle($enrollment, (int) $item->getKey());
            }
        }
    }

    /**
     * The enrolment this attempt belongs to.
     *
     * `enrollment_id` is nullable on the attempt (`StartAttempt` leaves it null
     * for an exam with no course), so the fallback looks it up the same way
     * `StartAttempt` does rather than giving up on a row that predates the
     * enrolment.
     */
    private function enrollmentFor(?int $enrollmentId, int $examId, int $studentUserId): ?Enrollment
    {
        if ($enrollmentId !== null) {
            return Enrollment::query()->withoutWorkspaceScope()->find($enrollmentId);
        }

        return Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $studentUserId)
            ->whereIn('course_id', function ($query) use ($examId): void {
                $query->select('course_id')->from('exams')->where('id', $examId);
            })
            ->first();
    }
}
