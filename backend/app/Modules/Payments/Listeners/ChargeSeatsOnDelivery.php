<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Payments\Actions\ChargeSessionSeats;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The bridge from teaching to money, and the only one.
 *
 * Two neighbouring events are left alone DELIBERATELY, and both omissions are
 * facts about the 005 code rather than preferences:
 *
 *   · `AttendanceConfirmed` exists and would look like the natural subject —
 *     FR-051 even phrased consumption as following it. But it is dispatched
 *     unconditionally from CloseClassSession.php:66, including for a session
 *     the teacher never taught, which FR-025أ forbids charging for; and it
 *     carries no seat count, so listening to it would force this module to
 *     derive the number from live bookings, which FR-007أ forbids outright.
 *
 *   · `AttendanceOverridden` has no financial effect at all. Consumption is per
 *     frozen SEAT, not per attendance status (FR-025د · Q-6), so a teacher
 *     changing a mark from Absent to Excused moves no money and there is
 *     nothing to correct.
 *
 * Written down because a subscription that is merely absent gets proposed again
 * in six months. Precedent: SettlementServiceProvider.php:54.
 *
 * `ShouldHandleEventsAfterCommit` because CloseClassSession writes the register
 * and the status inside a transaction — a listener that ran before it committed
 * would charge against a session that can still roll back.
 */
class ChargeSeatsOnDelivery implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly ChargeSessionSeats $action,
    ) {}

    public function handle(SessionDelivered $event): void
    {
        $this->action->handle($event->session, $event->billableSeats);
    }
}
