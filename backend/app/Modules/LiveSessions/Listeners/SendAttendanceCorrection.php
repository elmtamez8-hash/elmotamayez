<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\LiveSessions\Actions\SendSessionReport;
use App\Modules\LiveSessions\Events\AttendanceOverridden;

/**
 * FR-037 — a report that turned out wrong is corrected, not left standing.
 *
 * Only for rows already reported. An edit made before the delay elapses needs no
 * correction: the first report has not gone yet and will carry the new status,
 * and sending a "correction" to a guardian who received nothing is a message
 * about a message they never saw.
 */
class SendAttendanceCorrection
{
    public function __construct(
        private readonly SendSessionReport $send,
    ) {}

    public function handle(AttendanceOverridden $event): void
    {
        if ($event->attendance->report_sent_at === null) {
            return;
        }

        $this->send->handle($event->attendance, isCorrection: true);
    }
}
