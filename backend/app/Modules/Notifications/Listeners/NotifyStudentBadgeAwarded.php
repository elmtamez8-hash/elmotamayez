<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Gamification\Events\BadgeAwarded;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A badge was earned, once and for the first time (spec 009 · FR-017).
 *
 * No `workspaceId`, for the reason written on {@see NotifyStudentLevelUp}: a badge
 * belongs to the person, not to whichever teacher's lesson happened to complete it.
 */
class NotifyStudentBadgeAwarded implements ShouldQueue
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(BadgeAwarded $event): void
    {
        $this->dispatch->handle(new NotificationRequest(
            recipient: $event->student,
            type: NotificationType::BadgeAwarded,
            variables: [
                'student_name' => $event->student->name,
                'badge_name' => $event->badge->name_ar,
            ],
            actionUrl: '/progress',
        ));
    }
}
