<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/** A session now exists on the calendar. Notifications schedule reminders off it. */
class SessionScheduled
{
    use Dispatchable;

    public function __construct(
        public readonly ClassSession $session,
    ) {}
}
