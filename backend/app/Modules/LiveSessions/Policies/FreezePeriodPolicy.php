<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class FreezePeriodPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::FREEZE_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, FreezePeriod $period): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($period))->denied()) {
            return $workspaceCheck;
        }

        // FR-044: readable with its reason and its author, so a suspended session
        // can be explained to the student who booked it.
        return $user->can(Permissions::FREEZE_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::FREEZE_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, FreezePeriod $period): Response
    {
        return $this->view($user, $period);
    }
}
