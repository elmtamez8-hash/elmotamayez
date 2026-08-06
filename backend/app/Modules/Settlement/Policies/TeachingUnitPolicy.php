<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Policies;

use App\Models\User;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Reading a teacher's units is reading their contract, not running their class.
 *
 * `SETTLEMENT_STATEMENT_VIEW` and nothing operational: an assistant teacher holds
 * every session permission there is and still fails here (FR-020 · SC-010). The
 * workspace check on top of it is what stops one teacher reading another's
 * (FR-019).
 */
class TeachingUnitPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::SETTLEMENT_STATEMENT_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, TeachingUnit $unit): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($unit))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SETTLEMENT_STATEMENT_VIEW)
            ? Response::allow()
            : Response::deny();
    }
}
