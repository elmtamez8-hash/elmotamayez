<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\LiveSessions\Events\SessionCompleted;
use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;

/**
 * Recomputes a teacher's counters after each of their sessions ends.
 *
 * Listens to SessionCompleted rather than SessionDelivered, because a session
 * that ended WITHOUT being delivered is exactly what should move the ratio down
 * — subscribing to delivery alone would make a teacher who never turned up
 * invisible to their own attendance rate.
 */
class UpdateTeacherCounters
{
    public function handle(SessionCompleted $event): void
    {
        SyncTeacherCountersJob::dispatch((int) $event->session->teacher_profile_id);
    }
}
