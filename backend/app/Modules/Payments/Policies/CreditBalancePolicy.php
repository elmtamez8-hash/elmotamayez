<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Two readers, two different questions.
 *
 * Ownership is checked BEFORE the workspace, and that order is load-bearing: a
 * student studying with three teachers has one current workspace, so the
 * workspace check would deny them their own balance in the other two — the very
 * case Q-7 splits the balance per course to serve.
 *
 * Nothing here grants a write. Every movement is an Action that writes a ledger
 * entry; a balance updated through a policy-guarded endpoint would be a number
 * with no row behind it.
 */
class CreditBalancePolicy extends BasePolicy
{
    public function view(User $user, CreditBalance $balance): Response
    {
        if ($balance->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($balance))->denied()) {
            return $workspaceCheck;
        }

        // The teacher and their assistant see credits and withholding — never a
        // price and never a component (FR-021ب).
        return $user->can(Permissions::BILLING_BALANCE_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    /**
     * Move the ceiling on how far this balance may go negative.
     *
     * Platform-level, not the teacher's: raising it creates a debt the platform
     * carries alone (Q-4), and the teacher is the party paid out of it.
     */
    public function manageLimit(User $user, CreditBalance $balance): Response
    {
        return $user->can(Permissions::BILLING_LIMIT_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    /** Grant a bonus or write a correcting adjustment. Platform-level, same reason. */
    public function adjust(User $user, CreditBalance $balance): Response
    {
        return $user->can(Permissions::BILLING_CREDITS_ADJUST)
            ? Response::allow()
            : Response::deny();
    }
}
