<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\LiveSessions\Events\SessionRescheduleRequested;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

/**
 * «يطلب سامي تأجيل حصة السبت إلى الأحد ٦م».
 *
 * ⚠️ `ShouldHandleEventsAfterCommit`. The row is written and the event fires
 * straight after it; without this a real queue worker picks the job up before
 * the commit lands, reads no row, and the queue never lights up — invisibly, and
 * only on the `redis` connection production runs, not the `sync` one every test
 * uses.
 *
 * ⚠️ THE RECIPIENT IS THE SESSION'S HOST, NOT THE COURSE'S AUTHOR. A lesson has
 * a `teacher_profile_id` of its own and it is the person whose calendar the move
 * would rewrite — a session taught by an assistant on somebody else's course
 * would otherwise send the ask to a teacher who cannot answer it.
 */
class NotifyTeacherSessionRescheduleRequested implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(SessionRescheduleRequested $event): void
    {
        $request = $event->request;
        $session = $request->classSession;
        $student = $request->student;
        $teacher = $session?->teacherProfile?->user;

        // None of the three is an error and none is a message anybody can act
        // on: a profile can outlive its user row, and a student whose account is
        // gone has nothing to ask for.
        if ($session === null || $student === null || $teacher === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::SessionRescheduleRequested,
            variables: [
                'student_name' => $student->name,
                'title' => $session->title,
                // Rendered in the platform's declared timezone, the
                // `NotifySeatHolders` spelling: the row is a UTC instant and a
                // teacher reading «18:00» must see the hour the student meant.
                'from_time' => $this->local($request->from_starts_at),
                'to_time' => $this->local($request->to_starts_at),
                // Never empty: `TemplateRenderer` counts a present-but-blank
                // variable as MISSING and refuses the whole message, so an ask
                // with no words typed would be dropped in silence.
                'student_reason' => $request->student_reason ?? 'لم يُذكر سبب.',
            ],
            actionUrl: '/manage/reschedule-requests',
            subject: $student,
            workspaceId: (int) $request->workspace_id,
        ));
    }

    private function local(Carbon $at): string
    {
        return $at->copy()->setTimezone($this->settings->timezone())->format('Y-m-d H:i');
    }
}
