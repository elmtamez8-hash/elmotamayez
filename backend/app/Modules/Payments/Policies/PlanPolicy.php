<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may write a plan, and who may price it (spec 011 · US4 · FR-025).
 *
 * ⚠️ TWO PERMISSIONS ON ONE ROW, AND THE SPLIT IS THE REQUIREMENT. A plan is
 * genuinely the teacher's — they decide how long a month of them lasts and what
 * it covers — while a subscription is access to TEACHING, so its price is the
 * platform's (Q4). `price()` is therefore not a variant of `update()`: the
 * workspace owner, the highest tenant role there is, must fail it.
 *
 * ⚠️ THE PRICE GUARD IS ALSO IN THE ACTION. A policy answers about a route; the
 * panel, a seeder and any future importer reach `SavePlan` with no route behind
 * them, which is why that Action refuses the field itself. Two guards for one
 * rule, at the two doors that actually exist.
 *
 * ⚠️ AND THIS IS BOUND EXPLICITLY IN `PaymentsServiceProvider`. Laravel's guesser
 * fails OPEN into «no policy applies», so a policy written and not registered
 * denies nothing at all.
 *
 * ⚠️ THERE IS NO `view()` A STUDENT USES. Browsing a teacher's plans is a
 * question about a workspace the student is not a member of — the context is
 * null, there is no spatie team id, and every `can()` below is already false — so
 * the catalogue is answered by the Action's own `sellable()` predicate instead.
 */
class PlanPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::PLANS_MANAGE) || $user->can(Permissions::PLANS_PRICE)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, Plan $plan): Response
    {
        // A platform officer prices plans across every workspace, so the
        // workspace check cannot stand above their permission: `WorkspaceContext`
        // falls back to `users.last_workspace_id` for them exactly as it does for
        // everybody else, and one arbitrary workspace of theirs is not the set
        // they are entitled to.
        if ($user->can(Permissions::PLANS_PRICE)) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($plan))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::PLANS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::PLANS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Plan $plan): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($plan))->denied()) {
            return $workspaceCheck;
        }

        return $this->create($user);
    }

    /** The platform's half, and no tenant role holds it. */
    public function price(User $user, Plan $plan): Response
    {
        return $user->can(Permissions::PLANS_PRICE)
            ? Response::allow()
            : Response::deny('سعر الباقة تحدّده المنصّة.');
    }

    /**
     * ⚠️ RETIRED WITH `is_active`, NEVER DELETED. `subscriptions.plan_id` points
     * here and a student's own subscription must keep naming what they bought —
     * a deleted plan turns every past and running subscription into a row with
     * nothing behind it, including the one the expiry notice reads a title from.
     *
     * Repeated on the Filament Resource, because `BasePolicy::before()` waves a
     * super admin past every method here and a super admin is exactly who is
     * standing at that screen.
     */
    public function delete(User $user, Plan $plan): Response
    {
        return Response::deny('تُوقَف الباقة ولا تُحذَف، لأن كل اشتراك تمّ بها ما يزال يشير إليها.');
    }
}
