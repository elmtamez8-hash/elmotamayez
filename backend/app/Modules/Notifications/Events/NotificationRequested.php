<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Events;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification record exists and its deliveries are about to be queued.
 *
 * First of the four-event chain (FR-010). The chain is the observable seam:
 * everything after dispatch happens in a queue worker, so without it a message
 * that never arrives leaves nothing to look at.
 */
class NotificationRequested
{
    use Dispatchable;

    public function __construct(public readonly Notification $notification) {}
}
