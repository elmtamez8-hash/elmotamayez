<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\Plan;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

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
 */
class SetPlanPrice extends Action
{
    use LogsActivity;

    public function handle(Plan $plan, ?int $priceMinor): Plan
    {
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
