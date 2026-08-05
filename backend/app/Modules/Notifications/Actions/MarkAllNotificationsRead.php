<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Actions\Action;

class MarkAllNotificationsRead extends Action
{
    /** @return int how many were still unread */
    public function handle(User $user): int
    {
        // One UPDATE, not a loop: a user clearing 10,000 notifications should not
        // load 10,000 models to set one column on each.
        return Notification::query()
            ->forRecipient($user)
            ->unread()
            ->update(['read_at' => now()]);
    }
}
