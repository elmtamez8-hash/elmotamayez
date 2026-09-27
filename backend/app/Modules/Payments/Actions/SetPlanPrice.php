<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The platform's half of a plan (T091 · T097 · FR-025 · FR-030).
 *
 * ⚠️ REPRICING NEVER TOUCHES A LIVE SUBSCRIPTION, AND NOTHING HERE HAS TO
 * REMEMBER THAT. `subscriptions.price_minor` is a snapshot written at activation
 * from the ORDER, so FR-030 holds by construction rather than by a condition
 * somebody could drop: there is no query from here to a subscription at all.
 * Reading the plan's price at renewal instead is what would let a change made
 * today rewrite what a student agreed to last month.
 *
 * ⚠️ AND THE PLAN IS FETCHED WITHOUT THE WORKSPACE SCOPE BY ITS CALLER. A
 * platform officer has a `users.last_workspace_id` like everybody else, so
 * `WorkspaceContext::id()` resolves to some arbitrary workspace of theirs and
 * route-model binding would answer 404 for every plan outside it — a report that
 * silently covers one teacher, which is the defect the audit chain already
 * shipped once.
 *
 * ⚠️ AND THE PERMISSION IS ASKED HERE, NOT ONLY AT THE SCREEN. Three doors reach
 * this Action (the pricing screen, the create-on-behalf page, a plan-change
 * decision) and each asked `plans.price` in its own way — or, for the pricing
 * screen, asked the teacher's `PlanPolicy::update()` instead. The actor is a
 * REQUIRED argument on purpose: a nullable one that skips the check when absent
 * is a guard nobody has to pass.
 */
class SetPlanPrice extends Action
{
    use LogsActivity;

    /**
     * @throws AuthorizationException when the actor does not hold the platform pricing permission
     * @throws DomainException when the price is negative
     */
    public function handle(User $by, Plan $plan, ?int $priceMinor): Plan
    {
        if (! $by->can(Permissions::PLANS_PRICE)) {
            throw new AuthorizationException('سعر الباقة تحدّده المنصّة.');
        }

        if ($priceMinor !== null && $priceMinor < 0) {
            throw new DomainException('سعر الباقة لا يكون سالباً.');
        }

        $before = $plan->price_minor;

        // Not fillable — deliberately. See the model.
        $plan->forceFill(['price_minor' => $priceMinor])->save();

        $this->logActivity('plan.priced', $plan, [
            'from_minor' => $before,
            'to_minor' => $priceMinor,
        ]);

        return $plan;
    }
}
