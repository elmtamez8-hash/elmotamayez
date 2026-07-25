<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Notifications\Notifications\EnrollmentCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStudentEnrolled implements ShouldQueue
{
    public function handle(EnrollmentCreated $event): void
    {
        $event->enrollment->student?->notify(new EnrollmentCreatedNotification($event->enrollment));
    }
}
