<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Models\Notification;
use App\Shared\Actions\Action;

/**
 * Idempotent by construction (FR-014): re-reading an already-read notification
 * leaves read_at where it was, so a double-click does not rewrite when the user
 * saw it.
 */
class MarkNotificationRead extends Action
{
    public function handle(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }
}
