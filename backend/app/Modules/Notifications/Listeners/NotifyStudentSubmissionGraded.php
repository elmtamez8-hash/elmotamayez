<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Assessments\Events\SubmissionGraded;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The mark is in (FR-050).
 *
 * ⚠️ THE PENALTY IS NAMED IN THE MESSAGE, not left for the student to work out
 * from a number lower than the one they expected. A mark reduced with no stated
 * cause is the one they write to their teacher about — and the cause was stated
 * in the assignment's own policy days earlier, so repeating it here costs
 * nothing and closes the question.
 *
 * ⚠️ AND THE CLAUSE IS NEVER AN EMPTY STRING. `TemplateRenderer` counts
 * present-but-empty as MISSING and refuses to render — deliberately, so a body
 * reading «درجتك: » never goes out — and `DispatchNotification` logs the refusal
 * rather than failing. So an "omit it when there is nothing to say" clause does
 * not omit the clause: it drops the entire notification, for exactly the
 * students whose work was on time. Both branches below say something true.
 */
class NotifyStudentSubmissionGraded implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(SubmissionGraded $event): void
    {
        $submission = $event->submission;
        $assignment = $submission->assignment;
        $student = $submission->student;

        if ($assignment === null || $student === null) {
            return;
        }

        $penalty = (float) $submission->late_penalty_applied_pct;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::AssignmentGraded,
            variables: [
                'student_name' => $student->name,
                'assignment_title' => $assignment->title,
                'score' => rtrim(rtrim(number_format((float) $submission->score, 2, '.', ''), '0'), '.'),
                'points' => (string) $assignment->points,
                'penalty_note' => $penalty > 0
                    ? 'خُصم '.rtrim(rtrim(number_format($penalty, 2, '.', ''), '0'), '.').'٪ للتأخير.'
                    // True of an on-time hand-in AND of a late one under an
                    // `accept` policy, which is why it is not «سُلّم في الموعد».
                    : 'لم يُخصم شيء للتأخير.',
            ],
            // ⚠️ THE LIST, BECAUSE THERE IS NO DETAIL ROUTE. Handing in and
            // reading a mark both happen on `/assignments`; a per-assignment
            // href is a 404. The ceiling is that the reader lands on the whole
            // list rather than on this row.
            actionUrl: '/assignments',
            workspaceId: (int) $submission->workspace_id,
        ));
    }
}
