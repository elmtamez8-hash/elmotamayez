<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Note what this listener does not say: which channel, which provider, what the
 * message reads like. It names a person, a type and the facts. Everything else is
 * the architecture's decision (FR-002) — which is what lets a new channel reach
 * this notification without the file being opened.
 */
class NotifyStudentEnrolled implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(EnrollmentCreated $event): void
    {
        $enrollment = $event->enrollment;
        $course = $enrollment->course;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $enrollment->student,
            type: NotificationType::EnrollmentCreated,
            variables: [
                'name' => $enrollment->student->name,
                'course_title' => $course->title,
            ],
            actionUrl: '/courses/'.$course->uuid,
            subject: $enrollment->student,
            workspaceId: $enrollment->workspace_id,
        ));
    }
}
