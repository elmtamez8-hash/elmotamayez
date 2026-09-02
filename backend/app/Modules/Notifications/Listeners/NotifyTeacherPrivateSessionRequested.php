<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\LiveSessions\Events\PrivateSessionRequested;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * «يطلب سامي حصة خاصة الثلاثاء ٦م» (FR-018).
 *
 * ⚠️ `ShouldHandleEventsAfterCommit`. The row is written by a conditional INSERT
 * and the event fires straight after it; without this a real queue worker picks
 * the job up before the commit lands, reads no row, and the teacher's queue never
 * lights up — invisibly, and only on the `redis` connection production runs, not
 * on the `sync` one every test uses.
 *
 * The recipient is the course's author, on the precedent of
 * {@see NotifyTeacherCohortTransferRequested}: a workspace may hold several people
 * who could plausibly be «the teacher», and the one who built the course is the
 * one whose availability the request was picked from.
 */
class NotifyTeacherPrivateSessionRequested implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(PrivateSessionRequested $event): void
    {
        $request = $event->request;
        $course = $request->course;
        $student = $request->student;
        $teacher = $course?->creator;

        // `created_by` is nullable — a course can outlive its author — and a
        // student whose account is gone has nothing to ask for. Neither is an
        // error, and neither is a message anybody can act on.
        if ($course === null || $student === null || $teacher === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::PrivateSessionRequested,
            variables: [
                'student_name' => $student->name,
                'course_title' => $course->title,
                // Rendered in the platform's declared timezone, the
                // `NotifySeatHolders` spelling: the row is a UTC instant and a
                // student reading «18:00» in a message must see the hour their
                // teacher meant.
                'session_time' => $request->starts_at
                    ->copy()
                    ->setTimezone($this->settings->timezone())
                    ->format('Y-m-d H:i'),
                'duration' => (string) $request->duration_minutes,
            ],
            // The queue itself: the teacher opens this to press one of two
            // buttons, and the deadline is running.
            actionUrl: '/manage/private-sessions',
            subject: $student,
            workspaceId: (int) $request->workspace_id,
        ));
    }
}
