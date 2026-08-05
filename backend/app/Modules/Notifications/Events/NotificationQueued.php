<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Events;

use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One channel's delivery job is on the queue. Fires once per channel, so a
 * notification going out on three channels produces three of these.
 */
class NotificationQueued
{
    use Dispatchable;

    public function __construct(public readonly NotificationDelivery $delivery) {}
}
