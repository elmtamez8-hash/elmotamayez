<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The session is over.
 *
 * Over, not necessarily taught. A session the teacher never joined fires this
 * and does NOT fire SessionDelivered — which is why they are two events and not
 * one with a boolean. A flag would make the condition optional for the listener;
 * a missing event cannot be overlooked (research §R7).
 */
class SessionCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly ClassSession $session,
    ) {}
}
