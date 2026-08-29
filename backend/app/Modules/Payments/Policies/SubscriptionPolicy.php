<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may read a subscription, and who may undo one.
 *
 * ⚠️ OWNERSHIP IS ASKED FIRST, AND IT IS NOT A PERMISSION. A student is a member
 * of no workspace, so `WorkspaceContext::id()` is null, there is no spatie team
 * id, and every `can()` below is already false for them — which is precisely the
 * shape that locked real students out of their own rows for a month in 006. The
 * ownership branch has to stand ABOVE every permission check or a subscriber
 * cannot read the subscription they paid for.
 *
 * ⚠️ CANCELLING IS A PLATFORM PERMISSION, NOT THE TEACHER'S AND NOT THE
 * STUDENT'S. It reverses a captured payment (see `CancelSubscription`), and
 * money leaving the platform is not a decision either party to the lesson takes
 * alone — the same reason `BILLING_PURCHASE_APPROVE` guards the approval it
 * undoes. A teacher who could cancel could refund out of a balance that is not
 * theirs; a student who could cancel could take a month back on its last day.
 */
class SubscriptionPolicy extends BasePolicy
{
    public function view(User $user, Subscription $subscription): Response
    {
        if ((int) $subscription->student_user_id === (int) $user->getKey()) {
            return Response::allow();
        }

        if ($user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            return Response::allow();
        }

        // The teacher whose workspace sold it: a subscriber is somebody they are
        // now teaching, and the plan, the dates and the coverage are facts about
        // their own workspace. The PRICE is not theirs to read and never reaches
        // them — that guard is on the Resource, where the payload is decided.
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($subscription))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::PLANS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function cancel(User $user, Subscription $subscription): Response
    {
        return $user->can(Permissions::BILLING_PURCHASE_APPROVE)
            ? Response::allow()
            : Response::deny('إلغاء الاشتراك واسترداد قيمته صلاحية منصّية.');
    }
}
