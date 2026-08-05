<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Policies;

use App\Models\User;
use App\Modules\Notifications\Models\Notification;

/**
 * A notification belongs to exactly one person (FR-016).
 *
 * The check is ownership of the row, not workspace membership: the feed is a
 * bridge entity guarded by recipient_user_id, so a student studying with four
 * teachers still has one stream. Callers translate a denial into 404 rather than
 * 403 — for a resource nobody may enumerate, confirming a uuid exists is itself
 * the leak.
 */
class NotificationPolicy
{
    public function view(User $user, Notification $notification): bool
    {
        return (int) $user->getKey() === (int) $notification->recipient_user_id;
    }

    public function update(User $user, Notification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
