<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Policies;

use App\Models\User;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The teacher reads their period; the platform closes it and pays it.
 *
 * Closing and paying are deliberately separate from reading and from each other:
 * they are the two irreversible acts in this module, and a single "manage
 * settlement" permission would hand both to whoever needed either.
 */
class SettlementPeriodPolicy extends BasePolicy
{
    public function view(User $user, SettlementPeriod $period): Response
    {
        if ($user->can(Permissions::SETTLEMENT_PERIOD_MANAGE)) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($period))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SETTLEMENT_STATEMENT_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function close(User $user, SettlementPeriod $period): Response
    {
        return $user->can(Permissions::SETTLEMENT_PERIOD_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function pay(User $user, SettlementPeriod $period): Response
    {
        return $user->can(Permissions::SETTLEMENT_PAYOUT_EXECUTE)
            ? Response::allow()
            : Response::deny();
    }
}
