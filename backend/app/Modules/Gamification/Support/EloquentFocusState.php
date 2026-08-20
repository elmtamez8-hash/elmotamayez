<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Models\User;
use App\Modules\Gamification\Enums\FocusSessionStatus;
use App\Modules\Gamification\Models\FocusSession;
use App\Shared\Contracts\FocusState;

/**
 * Gamification owns the focus session; Notifications asks through the interface.
 *
 * One indexed lookup on `(user_id, status)`. It runs on every notification
 * dispatch, which is why the index exists — and why there is no cache key beside
 * it: a second copy of this fact is what would make a missed close permanent.
 */
class EloquentFocusState implements FocusState
{
    public function isFocusing(User $user): bool
    {
        return FocusSession::query()
            ->where('user_id', $user->getKey())
            ->where('status', FocusSessionStatus::Running->value)
            ->exists();
    }
}
