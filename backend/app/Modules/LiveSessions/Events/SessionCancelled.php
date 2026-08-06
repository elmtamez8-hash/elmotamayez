<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session will not happen — cancelled outright, or suspended by a freeze.
 *
 * Carries the reason because the notification has to explain itself: "your
 * session is off" without a why is the message that generates the support
 * ticket it was meant to prevent.
 */
class SessionCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly ClassSession $session,
        public readonly ?string $reason = null,
    ) {}
}
