<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Settlement\Actions\AccrueTeachingUnits;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use App\Modules\Settlement\Models\TeachingUnit;

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
 */
class AccrueUnitsOnDelivery
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
