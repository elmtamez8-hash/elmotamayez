<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Policies;

use App\Models\User;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may act on a claim.
 *
 * ⚠️ THIS POLICY GOVERNS THE TEACHER'S SIDE ONLY. The student's own list does not
 * come through here: it is row ownership rather than permission, filtered by
 * `user_id` in the controller — the same distinction spec 005 drew between
 * SESSIONS_VIEW and ATTENDANCE_VIEW, where a reader without the permission still
 * gets their own row.
 */
class RedemptionPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::REDEMPTIONS_FULFILL) ? Response::allow() : Response::deny();
    }

    public function decide(User $user, Redemption $redemption): Response
    {
        return $user->can(Permissions::REDEMPTIONS_FULFILL)
            && (int) $redemption->workspace_id === (int) $user->last_workspace_id
            ? Response::allow()
            : Response::deny();
    }
}
