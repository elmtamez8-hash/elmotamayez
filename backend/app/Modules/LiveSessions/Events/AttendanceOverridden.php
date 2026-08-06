<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\Attendance;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A person changed what the register says about someone.
 *
 * An event rather than a call, because what follows an override is not the
 * override's business: today it is a correction to the guardian, and spec 006
 * will hang a billing recount off the same moment without editing this Action.
 */
class AttendanceOverridden
{
    use Dispatchable;

    public function __construct(
        public readonly Attendance $attendance,
    ) {}
}
