<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Marketplace\Events\TeacherChangesRequested;
use App\Modules\Notifications\Notifications\TeacherChangesRequestedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTeacherChangesRequested implements ShouldQueue
{
    public function handle(TeacherChangesRequested $event): void
    {
        $event->application->user?->notify(
            new TeacherChangesRequestedNotification($event->application, $event->reason),
        );
    }
}
