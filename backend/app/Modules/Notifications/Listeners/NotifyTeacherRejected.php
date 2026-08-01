<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Marketplace\Events\TeacherRejected;
use App\Modules\Notifications\Notifications\TeacherRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTeacherRejected implements ShouldQueue
{
    public function handle(TeacherRejected $event): void
    {
        $event->application->user?->notify(
            new TeacherRejectedNotification($event->application, $event->reason),
        );
    }
}
