<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class ClassSessionPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::SESSIONS_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, ClassSession $session): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($session))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SESSIONS_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::SESSIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, ClassSession $session): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($session))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SESSIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function cancel(User $user, ClassSession $session): Response
    {
        return $this->update($user, $session);
    }

    /**
     * Mute, remove, end.
     *
     * A separate ability from update: managing the calendar and controlling a
     * live room are different powers, and spec 010 will hand one to assistants
     * without the other.
     */
    public function host(User $user, ClassSession $session): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($session))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SESSIONS_HOST)
            ? Response::allow()
            : Response::deny();
    }
}
