<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Settlement\Actions\AccrueTeachingUnits;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The bridge, and the only one.
 *
 * `SessionDelivered` rather than `AttendanceConfirmed`, for two reasons that are
 * facts about the 005 code rather than preferences: the attendance event fires
 * unconditionally — including for a session the teacher never taught, which
 * FR-007ج forbids earning on — and it carries no seat count, so listening to it
 * would force this module to derive the number from live bookings, which
 * FR-007أ forbids outright.
 *
 * Nothing here reaches into LiveSessions beyond the event's payload, and nothing
 * in either direction touches the student billing context.
 *
 * ⚠️ QUEUED, AND IT WAS A PLAIN CLASS — WHICH MADE IT A SINGLE POINT OF FAILURE
 * FOR THE WHOLE END OF A LESSON.
 *
 * `SessionDelivered` fires exactly ONCE in a session's life: `CloseClassSession`
 * returns early on a terminal status, and by the time the event is dispatched the
 * transaction that wrote `status = completed` has already committed. Run
 * synchronously, a throw in here — a deadlock, a transient failure, the
 * unguarded `create()` in `compensateEmptySession()` — propagated straight back
 * into `CloseClassSession::handle()` and killed the three lines below it:
 * `SessionCompleted` (so the teacher's counters never moved AND the recording was
 * never ingested), `AttendanceConfirmed`, and `SendSessionReportsJob` (so no
 * guardian was ever told about that lesson). All three lost permanently, with
 * nothing that re-fires them and no sweep that notices.
 *
 * Queued, the failure lands in `failed_jobs` where it belongs and the rest of the
 * close runs. `ShouldHandleEventsAfterCommit` for the same reason its Payments
 * sibling carries it — and the two are now the same shape, which is the point:
 * one event with one synchronous listener among queued ones is a hazard that is
 * invisible until the day that listener throws.
 */
class AccrueUnitsOnDelivery implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly AccrueTeachingUnits $action,
    ) {}

    public function handle(SessionDelivered $event): void
    {
        $units = $this->action->handle($event->session, $event->billableSeats);

        foreach ($units as $unit) {
            // Announced only once it is actually an earning. A unit still waiting
            // for its recording is not money yet, and a listener told otherwise
            // would put a figure in front of the teacher that can still go away.
            if ($unit->status->countsTowardsTotal()) {
                TeachingUnitAccrued::dispatch($unit);
            }
        }
    }
}
