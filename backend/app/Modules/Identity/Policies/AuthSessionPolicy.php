<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;

/**
 * Own rows only.
 *
 * There is no teacher branch here, and its absence is the point. Elsewhere a
 * teacher may read a platform-owned row about a student who is enrolled with
 * them; a session is not one of those. Which machine someone studies on is not
 * academic information, so no enrolment makes it visible.
 */
class AuthSessionPolicy
{
    public function view(User $user, AuthSession $session): bool
    {
        return $session->user_id === $user->getKey();
    }

    public function delete(User $user, AuthSession $session): bool
    {
        return $session->user_id === $user->getKey();
    }
}
