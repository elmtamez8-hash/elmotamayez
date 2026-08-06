<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\LiveSessions\Events\SessionCancelled;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;

/**
 * Everyone who held a seat is told the session will not happen (FR-006 · FR-040).
 *
 * One listener for both endings — called off by the teacher, suspended by a
 * freeze — because from the seat's point of view they are the same news, and the
 * reason line is what distinguishes them. A second listener would be a second
 * place to forget the guardians.
 *
 * Reads the bookings rather than the register: the register does not exist for a
 * session that never ran, and the people owed an explanation are the ones who
 * had reserved the hour.
 */
class NotifySeatHolders
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(SessionCancelled $event): void
    {
        $session = $event->session;

        $seats = $session->bookings()->with('student')->get();

        $startsAt = $session->starts_at
            ->copy()
            ->setTimezone($this->settings->timezone())
            ->format('Y-m-d H:i');

        foreach ($seats as $seat) {
            $student = $seat->student;

            if ($student === null) {
                continue;
            }

            $this->dispatch->handle(new NotificationRequest(
                recipient: $student,
                type: NotificationType::SessionCancelled,
                variables: [
                    'title' => $session->title,
                    'starts_at' => $startsAt,
                    // Never empty: the template refuses a blank variable, and a
                    // seat taken away without a reason is the message that
                    // generates the support ticket it was meant to prevent.
                    'reason' => $event->reason ?? 'لم يُذكر سبب.',
                ],
                actionUrl: '/schedule',
                subject: $student,
                workspaceId: (int) $session->workspace_id,
            ));
        }
    }
}
