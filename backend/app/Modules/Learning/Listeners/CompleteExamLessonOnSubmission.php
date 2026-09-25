<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\ExamGateSatisfaction;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
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
 * It listens to `ExamSubmitted` rather than ONLY to `ExamPassed`, because which
 * of the two counts is the ITEM's decision (`FR-041`): under "يكفي أن يُحاول"
 * sitting it is the work, and a failing mark completes the item while still
 * telling the student they got it wrong. Under "يجب أن ينجح" only a pass does.
 * Listening to `ExamPassed` alone would silently impose the strict reading on
 * every item.
 *
 * ⛔ **AND IT LISTENS TO `ExamPassed` AS WELL — a second trigger, not a
 * replacement.** A paper carrying an essay is submitted as `pending_grading`
 * with `passed = false`, so at `ExamSubmitted` a pass-gated item is unmet; the
 * pass arrives later, when a person marks the essay and `FinalizeAttempt` fires
 * `ExamPassed` (or `ReviseGrade` turns a fail into a pass). Nothing re-ran the
 * item then — and that was invisible while `IssueCertificateIfEligible` issued a
 * certificate on the pass itself. Since 2026-09-25 the course certificate issues
 * on `CourseCompleted` alone (owner decision), so without this trigger a course
 * ending in a pass-gated essay exam could never reach 100%: no completion, no
 * certificate, for ever. Both triggers are idempotent — the gate is re-read
 * through `ExamGateSatisfaction`, and `MarkLessonComplete` claims its row with
 * `firstOrCreate`.
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
 * bookkeeping for.
 *
 * ⛔ **`ShouldQueueAfterCommit`, NOT `ShouldQueue` + `ShouldHandleEventsAfterCommit`
 * — measured against the framework, 2026-09-25.** For a QUEUED listener
 * `Illuminate\Events\Dispatcher` never consults `ShouldHandleEventsAfterCommit`:
 * `createClassCallable()` takes the queued branch before the after-commit
 * wrapper is reached, and `propagateListenerOptions()` sets `afterCommit` only
 * for `ShouldQueueAfterCommit` (every connection in `config/queue.php` has
 * `after_commit => false`). So the job was pushed from INSIDE `GradeAttempt`'s
 * transaction, before `FinalizeAttempt` had written `passed = true`: on `sync`
 * it ran there and then and read `passed = false`, so a pass-gated exam item was
 * never completed by its own pass (measured: `CertificateOnCompletionOnlyTest`'s
 * machine-marked case failed with the `ExamPassed` trigger removed, and passes
 * with it removed once this interface is in place); on Redis it raced the
 * commit. It went unseen because the certificate used to
 * issue on `ExamPassed` directly.
 */
class CompleteExamLessonOnSubmission implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function __construct(
        private readonly MarkLessonComplete $markComplete,
    ) {}

    public function handle(ExamSubmitted|ExamPassed $event): void
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

        if ($enrollment === null || ! $enrollment->grantsContentAccess()) {
            // An exam sat outside any enrolment — a standalone quiz, or a teacher
            // previewing their own. There is no progress to move.
            //
            // And an expired or cancelled enrolment is refused for the same
            // reason the controller refuses it: completing a lesson there would
            // tip the enrolment to `completed` and issue a certificate to someone
            // the API will not let finish anything.
            //
            // ⛔ A `completed` one is NOT refused: the teacher added an exam after
            // the student reached 100%, and sitting it must bring them back.
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
