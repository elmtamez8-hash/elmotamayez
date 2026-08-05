<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Marketplace\Events\TeacherChangesRequested;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTeacherChangesRequested implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(TeacherChangesRequested $event): void
    {
        $application = $event->application;
        $user = $application->user;

        if ($user === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $user,
            type: NotificationType::TeacherApplicationChangesRequested,
            variables: [
                'name' => $user->name,
                'reason' => $event->reason,
            ],
            actionUrl: '/teacher/application',
            workspaceId: $application->workspace_id,
        ));
    }
}
