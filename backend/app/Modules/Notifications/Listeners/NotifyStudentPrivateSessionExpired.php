<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\LiveSessions\Events\PrivateSessionExpired;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * «لم يصل ردّ، وانتهت المهلة» (FR-023 · FR-026).
 *
 * ⚠️ SENT ONCE, AND THE GUARD IS UPSTREAM. Every caller dispatches this behind
 * the conditional UPDATE that settled the row, so a second sweep over an already
 * `expired` request matches nothing and says nothing. A predicate here would be a
 * second answer to a question the settle already answered.
 *
 * ⚠️ AND IT SAYS THE BALANCE WAS NOT TOUCHED. Nothing was held (FR-017), and a
 * student who is not told so assumes an expired request cost them a credit —
 * which is the support ticket the whole no-hold design exists to avoid.
 */
class NotifyStudentPrivateSessionExpired implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(PrivateSessionExpired $event): void
    {
        $request = $event->request;
        $course = $request->course;
        $student = $request->student;

        if ($course === null || $student === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::PrivateSessionExpired,
            variables: [
                'course_title' => $course->title,
                'session_time' => $request->starts_at
                    ->copy()
                    ->setTimezone($this->settings->timezone())
                    ->format('Y-m-d H:i'),
            ],
            // The course page, which is where the teacher's declared hours are
            // and therefore where asking again starts.
            actionUrl: '/courses/'.$course->uuid,
            subject: $student,
            workspaceId: (int) $request->workspace_id,
        ));
    }
}
