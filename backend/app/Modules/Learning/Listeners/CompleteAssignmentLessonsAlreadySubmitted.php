<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Modules\Assessments\Models\Submission;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Events\AssignmentItemOpened;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Credits the homework students had already handed in before the item existed.
 *
 * The homework twin of {@see CompleteExamLessonsAlreadyAnswered}, and without it
 * the submission listener is a trap: homework is handed in from `/assignments`,
 * often before the teacher places it in the tree. For those students no
 * `AssignmentSubmitted` will fire again — and once the work is marked it CANNOT
 * fire again, because a marked hand-in is not replaceable — so the newly
 * published item would sit in their denominator for ever: capped below 100%,
 * no `CourseCompleted`, no certificate.
 *
 * `submitted_at IS NOT NULL`, because the missed sweep writes a row for every
 * student who handed nothing in; counting those would complete the item for
 * exactly the people who did not do it.
 *
 * Idempotent through `MarkLessonComplete`, and filtered before it so a
 * re-publish is nearly free. Queued and chunked for the exam listener's reason:
 * the set is every student of a course, which is not a number this code gets
 * to assume.
 */
class CompleteAssignmentLessonsAlreadySubmitted implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public function __construct(
        private readonly MarkLessonComplete $markComplete,
    ) {}

    public function handle(AssignmentItemOpened $event): void
    {
        $item = $event->lesson;

        if ($item->type !== LessonType::Assignment->value
            || $item->status !== ContentStatus::Published
            || $item->reference_id === null) {
            return;
        }

        $studentIds = Submission::query()
            ->withoutWorkspaceScope()
            ->where('assignment_id', $item->reference_id)
            ->whereNotNull('submitted_at')
            ->distinct()
            ->pluck('student_user_id');

        if ($studentIds->isEmpty()) {
            return;
        }

        Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $item->course_id)
            ->whereIn('student_user_id', $studentIds)
            // Active only — the exam backfill's rule and its reason: crediting an
            // expired enrolment flips it to `completed` and issues a certificate
            // to someone the API refuses to let finish a single lesson.
            ->where('status', 'active')
            ->whereDoesntHave('progress', fn ($query) => $query
                ->where('lesson_id', $item->getKey())
                ->where('status', 'completed'))
            ->with('course')
            ->chunkById(200, function ($enrollments) use ($item): void {
                foreach ($enrollments as $enrollment) {
                    $this->markComplete->handle($enrollment, (int) $item->getKey());
                }
            });
    }
}
