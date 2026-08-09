<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class WorkspacePolicy extends BasePolicy
{
    public function view(User $user, Workspace $workspace): Response
    {
        return $workspace->members()->where('user_id', $user->getKey())->exists()
            ? Response::allow()
            : Response::deny('You do not belong to this workspace.');
    }

    public function update(User $user, Workspace $workspace): Response
    {
        return $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny('Only the workspace owner can update settings.');
    }

    public function delete(User $user, Workspace $workspace): Response
    {
        return $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny('Only the workspace owner can delete the workspace.');
    }

    public function manageMembers(User $user, Workspace $workspace): Response
    {
        if (! $workspace->members()->where('user_id', $user->getKey())->exists()) {
            return Response::deny('You do not belong to this workspace.');
        }

        return $user->can(Permissions::MEMBERS_INVITE)
            ? Response::allow()
            : Response::deny('You are not authorized to manage workspace members.');
    }

    /**
     * Switch how this workspace collects (spec 006, FR-011).
     *
     * Membership first, permission second — the same order as manageMembers,
     * and for the same reason: BILLING_SETTINGS_MANAGE is a tenant permission,
     * so someone holding it in their own workspace would otherwise carry it into
     * anyone else's.
     */
    public function manageBillingSettings(User $user, Workspace $workspace): Response
    {
        if (! $workspace->members()->where('user_id', $user->getKey())->exists()) {
            return Response::deny('You do not belong to this workspace.');
        }

        return $user->can(Permissions::BILLING_SETTINGS_MANAGE)
            ? Response::allow()
            : Response::deny('You are not authorized to change billing settings.');
    }
}
