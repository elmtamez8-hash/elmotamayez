<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Modules\Assessments\Events\AssignmentSubmitted;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ticks off the assignment item when its homework is handed in.
 *
 * ⛔ THE DECISION THIS LISTENER CARRIES: an assignment item is completed by the
 * HAND-IN, never by a button. `LessonTypeRegistry` marks the type
 * `self_completable: false` because this class exists — a self-declare control
 * on homework is «I did it» without handing anything in, and the controller
 * refuses it at the door. The two flags move together: delete this listener and
 * the registry must go back to `true` in the same change, or every assignment
 * item is permanently incompletable and every enrolled student is capped below
 * 100% for ever (no `CourseCompleted`, no certificate).
 *
 * **Handing in, not passing.** The mark is where the work is judged, and it
 * arrives days later from a teacher; progress is «did the student do what this
 * position in the course asks», which for homework is handing it in — the exam
 * item's «يكفي أن يُحاول» reading. Waiting for the mark would hold a student's
 * sequence shut on the teacher's marking queue.
 *
 * **Late counts.** A late hand-in that the assignment ACCEPTED is a hand-in; the
 * lateness is recorded on the submission and costs marks, not progress. A late
 * hand-in the assignment REFUSES never gets here — `SubmitAssignment` throws
 * before the event. The student who missed a `reject` deadline is therefore not
 * completed, and their way out is the teacher's extension (`GrantExtension`),
 * which re-opens the hand-in and so this listener — the same shape as an exam
 * whose attempts are spent.
 *
 * **A resubmission changes nothing here.** It fires the event again and
 * `MarkLessonComplete` returns early on a row that is already complete.
 *
 * **Queued, and after the commit** — for the exam listener's reason: progress
 * bookkeeping must not be able to roll back the hand-in it is bookkeeping for.
 */
class CompleteAssignmentLessonOnSubmission implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function __construct(
        private readonly MarkLessonComplete $markComplete,
    ) {}

    public function handle(AssignmentSubmitted $event): void
    {
        $submission = $event->submission;

        if ($submission->submitted_at === null) {
            // The missed sweep writes rows with no hand-in in them. Not work.
            return;
        }

        $assignment = Assignment::query()->withoutWorkspaceScope()->find($submission->assignment_id);

        if ($assignment === null || $assignment->course_id === null) {
            // A course-less homework has no item in any tree (`ManageLessons`
            // refuses to place one), so there is no progress to move.
            return;
        }

        $enrollment = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $assignment->course_id)
            ->where('student_user_id', $submission->student_user_id)
            ->get()
            // A student may hold an old expired row beside a renewed one; the
            // live one is the one this hand-in belongs to. «Live» is `active` OR
            // `completed` — a completed student keeps the course, and homework
            // the teacher added afterwards must still count.
            ->first(static fn (Enrollment $row): bool => $row->grantsContentAccess());

        if ($enrollment === null) {
            // Expired or cancelled: completing a lesson there would tip the
            // enrolment to `completed` and issue a certificate to someone the
            // API will not let finish anything — the exam listener's rule.
            return;
        }

        $items = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $enrollment->course_id)
            ->referencing(LessonType::Assignment->value, (int) $assignment->getKey())
            // A draft item is in nobody's denominator — `AssignmentItemOpened`
            // credits this hand-in the day it is published.
            ->where('status', ContentStatus::Published)
            ->get();

        foreach ($items as $item) {
            $this->markComplete->handle($enrollment, (int) $item->getKey());
        }
    }
}
