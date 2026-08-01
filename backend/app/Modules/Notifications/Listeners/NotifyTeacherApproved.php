<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Notifications\Notifications\TeacherApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTeacherApproved implements ShouldQueue
{
    public function handle(TeacherApproved $event): void
    {
        $event->profile->user?->notify(new TeacherApprovedNotification($event->profile));
    }
}
