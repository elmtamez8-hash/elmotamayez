<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Events;

use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Terminal failure: either a permanent error, or the last retry gave up.
 * A transient failure that will be retried does NOT fire this.
 */
class NotificationFailed
{
    use Dispatchable;

    public function __construct(
        public readonly NotificationDelivery $delivery,
        public readonly string $reason,
        public readonly bool $permanent,
    ) {}
}
