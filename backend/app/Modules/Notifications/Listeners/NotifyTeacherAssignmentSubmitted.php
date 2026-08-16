<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Assessments\Events\AssignmentSubmitted;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Work arrived — tell whoever set it.
 *
 * The recipient is the assignment's AUTHOR, not "the teacher": an assistant who
 * wrote the homework is the person who knows what they were expecting, and a
 * workspace may have several people who could plausibly be meant.
 */
class NotifyTeacherAssignmentSubmitted implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(AssignmentSubmitted $event): void
    {
        $submission = $event->submission;
        $assignment = $submission->assignment;

        if ($assignment === null) {
            return;
        }

        $author = User::query()->find($assignment->created_by);
        $student = $submission->student;

        if ($author === null || $student === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $author,
            type: NotificationType::AssignmentSubmitted,
            variables: [
                'student_name' => $student->name,
                'assignment_title' => $assignment->title,
            ],
            actionUrl: '/manage/assignments/'.$assignment->uuid,
            workspaceId: (int) $submission->workspace_id,
        ));
    }
}
