<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Learning\Models\Enrollment;
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

        // ⚠️ ONE NOTICE PER ORDER. A plan covering eleven courses wrote eleven
        // rows and told the student eleven times (journey walk, 2026-10-23);
        // the order's first row speaks for all of them.
        if ($enrollment->order_id !== null && Enrollment::query()
            ->withoutGlobalScopes()
            ->where('order_id', $enrollment->order_id)
            ->where('student_user_id', $enrollment->student_user_id)
            ->where('id', '<', $enrollment->getKey())
            ->exists()) {
            return;
        }

        $course = $enrollment->course;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $enrollment->student,
            type: NotificationType::EnrollmentCreated,
            variables: [
                'name' => $enrollment->student->name,
                'course_title' => $course->title,
            ],
            // ⚠️ `/enrollments/{course}`, NOT `/courses/{uuid}`. The public
            // catalogue is `/courses` with no detail route at all; the screen a
            // newly enrolled student wants is their own curriculum page.
            actionUrl: '/enrollments/'.$course->uuid,
            subject: $enrollment->student,
            workspaceId: $enrollment->workspace_id,
        ));
    }
}
