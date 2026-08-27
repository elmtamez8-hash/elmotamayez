<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Learning\Events\CohortTransferDecided;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The answer the student is waiting for (FR-028ح).
 *
 * ⚠️ THE REJECTION CARRIES ITS REASON, AND THAT IS THE REQUIREMENT ITSELF. A
 * refusal that arrives as «لم يُقبل» and nothing else reads as a fault and is
 * submitted again for ever — which is the same queue back on the teacher's desk.
 * `DecideTransferRequest` refuses a rejection with no reason before it writes
 * anything, so `decision_reason` can never reach the renderer empty; were it
 * able to, TemplateRenderer would drop the whole message rather than send half
 * of one.
 */
class NotifyStudentCohortTransferDecided implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(CohortTransferDecided $event): void
    {
        $request = $event->request;
        $course = $request->course;
        $student = $request->student;

        if ($course === null || $student === null) {
            return;
        }

        $variables = [
            'to_cohort' => $request->toCohort->name,
            'course_title' => $course->title,
        ];

        if (! $event->approved) {
            $variables['decision_reason'] = (string) $request->decision_reason;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: $event->approved
                ? NotificationType::CohortTransferApproved
                : NotificationType::CohortTransferRejected,
            variables: $variables,
            // The course page: it carries the group panel, so an approved
            // student lands on their new schedule and a refused one on the
            // control that lets them ask again.
            actionUrl: '/enrollments/'.$course->uuid,
            workspaceId: (int) $request->workspace_id,
        ));
    }
}
