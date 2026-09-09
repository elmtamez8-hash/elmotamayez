<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student asked to move one lesson. Nothing has moved.
 */
class SessionRescheduleRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SessionRescheduleRequest $request,
    ) {}
}
