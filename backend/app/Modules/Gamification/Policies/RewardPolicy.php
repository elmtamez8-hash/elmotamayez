<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Policies;

use App\Models\User;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The teacher's own shop (FR-029).
 *
 * Workspace-owned: the rows carry a workspace_id and the reader is a member, so
 * the scope resolves and this policy is the row-level half. That is the opposite
 * of the student's routes, where the scope is inert and the guard has to be an
 * explicit filter.
 */
class RewardPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->allowIf($user->can(Permissions::REWARDS_MANAGE));
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Reward $reward): Response
    {
        return $this->allowIf(
            $user->can(Permissions::REWARDS_MANAGE)
            && (int) $reward->workspace_id === (int) $user->last_workspace_id,
        );
    }

    /**
     * Disable, never delete.
     *
     * Every redemption ever made points at its reward row — including the ones a
     * teacher still owes. Deleting it leaves the student's own history showing a
     * claim on nothing.
     */
    public function delete(User $user, Reward $reward): Response
    {
        return Response::deny('تُعطَّل المكافأة ولا تُحذف: طلباتُ الاستبدال السابقة ما تزال تشير إليها.');
    }

    private function allowIf(bool $condition): Response
    {
        return $condition ? Response::allow() : Response::deny();
    }
}
