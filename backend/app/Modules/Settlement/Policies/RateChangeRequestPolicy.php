<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Policies;

use App\Models\User;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Asking and deciding are two different permissions held by two different people.
 *
 * If one permission covered both, a teacher could approve their own rate — and
 * the approval step exists precisely because approving one moves the sale price
 * the whole marketplace shows (Q2).
 */
class RateChangeRequestPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->canAny([
            Permissions::SETTLEMENT_RATE_REQUEST,
            Permissions::SETTLEMENT_RATE_APPROVE,
        ]) ? Response::allow() : Response::deny();
    }

    public function view(User $user, RateChangeRequest $request): Response
    {
        if ($user->can(Permissions::SETTLEMENT_RATE_APPROVE)) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($request))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SETTLEMENT_RATE_REQUEST)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::SETTLEMENT_RATE_REQUEST)
            ? Response::allow()
            : Response::deny();
    }

    public function decide(User $user, RateChangeRequest $request): Response
    {
        return $user->can(Permissions::SETTLEMENT_RATE_APPROVE)
            ? Response::allow()
            : Response::deny();
    }
}
