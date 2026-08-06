<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The register is settled: every frozen seat has a row (FR-023أ · FR-050).
 *
 * Named as its own step rather than implied by completion, because FR-051
 * forbids any financial consumption before it. An implicit boundary cannot be
 * asserted on; this one is a moment with a timestamp (SC-015).
 */
class AttendanceConfirmed
{
    use Dispatchable;

    public function __construct(
        public readonly ClassSession $session,
    ) {}
}
