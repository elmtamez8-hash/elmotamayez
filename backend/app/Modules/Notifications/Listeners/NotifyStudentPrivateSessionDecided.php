<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\LiveSessions\Events\PrivateSessionDecided;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The answer, either way (FR-018 · FR-019).
 *
 * ⚠️ THE REFUSAL IS THE HALF THE REQUIREMENT IS ABOUT. An acceptance announces
 * itself — the lesson turns up in the timetable — while a refusal that reaches
 * nobody is indistinguishable from a request still waiting, and is submitted
 * again for ever.
 *
 * ⚠️ AND THE TWO ARE DIFFERENT TYPES, NOT ONE TYPE WITH A FLAG.
 * `TemplateRenderer` treats a present-but-empty variable as MISSING and refuses
 * the whole message, so a single template holding `{{ decision_reason }}` would
 * drop every acceptance on the platform in silence.
 */
class NotifyStudentPrivateSessionDecided implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(PrivateSessionDecided $event): void
    {
        $request = $event->request;
        $course = $request->course;
        $student = $request->student;

        if ($course === null || $student === null) {
            return;
        }

        $when = $request->starts_at
            ->copy()
            ->setTimezone($this->settings->timezone())
            ->format('Y-m-d H:i');

        $variables = [
            'course_title' => $course->title,
            'session_time' => $when,
        ];

        if ($event->accepted) {
            $variables['duration'] = (string) $request->duration_minutes;
        } else {
            // Never empty: the renderer refuses a blank variable, and a refusal
            // with no reason is the message that generates the support ticket it
            // was meant to prevent. `DecidePrivateSessionRequest` demands one
            // before it writes, so this fallback should be unreachable — and is
            // here because «should be» is not a guarantee about a column.
            $variables['decision_reason'] = $request->decision_reason ?? 'لم يُذكر سبب.';
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: $event->accepted
                ? NotificationType::PrivateSessionAccepted
                : NotificationType::PrivateSessionRejected,
            variables: $variables,
            // Accepted, the lesson is in their timetable; refused, the place to
            // go is the course page, where the teacher's other free hours are.
            actionUrl: $event->accepted ? '/schedule' : '/courses/'.$course->uuid,
            subject: $student,
            workspaceId: (int) $request->workspace_id,
        ));
    }
}
