<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Events;

use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Foundation\Events\Dispatchable;

class NotificationDelivered
{
    use Dispatchable;

    public function __construct(public readonly NotificationDelivery $delivery) {}
}
