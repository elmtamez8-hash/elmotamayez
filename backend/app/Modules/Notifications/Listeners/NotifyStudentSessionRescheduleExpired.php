<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\LiveSessions\Events\SessionRescheduleExpired;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * «لم يصل ردّ، وبقي موعدُ الحصّة كما هو».
 *
 * ⚠️ UNTIL THIS, AN EXPIRED REQUEST TOLD NOBODY. The sweep settled the row and
 * the student learned of it only by opening their timetable and reading «منتهٍ»
 * beside the button — a student who asked to move Saturday's lesson and heard
 * nothing does not know whether to turn up on Saturday.
 *
 * ⚠️ THE REQUESTER ALONE, as with a refusal: nothing moved, so the classmates
 * have nothing to hear, and a guardian was never told about the ask.
 *
 * ⚠️ AND THE TIME IS THE REQUEST'S `from_starts_at`, which is the session's hour
 * as it was asked about — and, because an expiry moves nothing, still its hour.
 */
class NotifyStudentSessionRescheduleExpired implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(SessionRescheduleExpired $event): void
    {
        $request = $event->request;
        $session = $request->classSession;
        $student = $request->student;

        if ($session === null || $student === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::SessionRescheduleExpired,
            variables: [
                'title' => $session->title,
                'from_time' => $request->from_starts_at
                    ->copy()
                    ->setTimezone($this->settings->timezone())
                    ->format('Y-m-d H:i'),
            ],
            // The timetable, where the request and its status are shown beside
            // the lesson — the same destination the other reschedule answers use.
            actionUrl: '/schedule',
            subject: $student,
            workspaceId: (int) $request->workspace_id,
        ));
    }
}
