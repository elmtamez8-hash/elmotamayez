<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The teacher showed up, stayed, and the session ran to its end (FR-056).
 *
 * This is the event billing (006) and payout (014) hang off: no credit is
 * consumed and no earning is generated without it (FR-058). It is fired from
 * exactly one place — CloseClassSession — so the rule is read in one file
 * instead of chased across controllers.
 *
 * `billableSeats` travels with it because it is a fact about a past moment: what
 * was booked when the cancellation window shut. Consumers must not recompute it
 * from live bookings (FR-060).
 */
class SessionDelivered
{
    use Dispatchable;

    public function __construct(
        public readonly ClassSession $session,
        public readonly int $billableSeats,
    ) {}
}
