<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Shared\Actions\Action;
use DomainException;

/**
 * A student buys a subscription (T092 · FR-025 · FR-026).
 *
 * ⚠️ NOTHING IS ACTIVATED HERE. A manual bank transfer takes days to be
 * witnessed, so this writes an `Order(kind: subscription)` and stops;
 * {@see App\Modules\Payments\Listeners\ActivateSubscription} does the rest when
 * the money is approved or captured. Activating on purchase would be a month of
 * access handed out against a transfer that may never arrive.
 *
 * ⚠️ THE PLAN IS RESOLVED INSIDE THIS ACTION, NEVER BY ROUTE-MODEL BINDING.
 * `Plan` carries `BelongsToWorkspace`, which protects nothing on a buyer's path:
 * a student is a member of no workspace, `WorkspaceContext::id()` is null, and
 * `WorkspaceScope::apply()` adds no condition at all. An implicit `{plan}` would
 * resolve any teacher's plan, including an inactive or unpriced one.
 *
 * ⚠️ AND THERE IS NO PARTICIPATION GUARD, DELIBERATELY — the opposite of
 * `ListCreditPackages`, which refuses a stranger with a 403. That guard exists
 * because a credit package's price is `(approved rate + constants) × credits`,
 * so two totals solve for the teacher's settlement rate exactly. A plan's price
 * is a number a platform officer typed; it derives from nothing and reveals
 * nothing. Subscribing is also how a student STARTS with a teacher, so requiring
 * an enrolment first would close the door this feature exists to open.
 */
class PurchaseSubscription extends Action
{
    public function handle(User $buyer, string $planUuid): Order
    {
        $plan = Plan::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $planUuid)
            ->sellable()
            ->first();

        if ($plan === null) {
            // One sentence for «no such plan», «switched off» and «not priced
            // yet». The three are indistinguishable to a buyer and telling them
            // apart would say which teachers have plans awaiting a price.
            throw new DomainException('هذه الباقة غير متاحة.');
        }

        $this->guardCoverageStillExists($plan);

        return Order::create([
            'workspace_id' => $plan->workspace_id,
            'user_id' => $buyer->getKey(),
            // A course-scoped plan stamps the course so the order reads sensibly
            // in a list; a workspace-scoped one has no single course to name.
            'course_id' => $this->coverageCourseId($plan),
            'kind' => OrderKind::Subscription,
            // ⚠️ THE PRICE IS COPIED ONTO THE ORDER AND THE SUBSCRIPTION IS LATER
            // BUILT FROM THE ORDER, NOT FROM THE PLAN. A manual transfer takes
            // days; the plan can legitimately be repriced inside that lag, and
            // reading it at activation would charge this student a number they
            // were never shown (FR-030).
            'amount_minor' => (int) $plan->price_minor,
            'currency' => $plan->currency,
            'provider' => 'manual',
            'status' => 'pending',
            /*
            | ⚠️ THE PLAN TRAVELS ON THE ORDER'S METADATA, NOT IN A COLUMN.
            | `orders` is shared by four kinds and a `plan_id` on it would be
            | null for three of them. Written server-side from the plan resolved
            | above, never from the request body — the buyer names a uuid and
            | this Action decides what it means.
            */
            'metadata' => ['plan_uuid' => (string) $plan->uuid],
        ]);
    }

    /**
     * A course-scoped plan whose course has been withdrawn sells nothing.
     *
     * The subscription itself survives a course being unpublished — coverage
     * simply drops it and the rest stays (data-model §٧) — but a plan that covers
     * ONE course and nothing else would sell a month of access to nothing at all.
     */
    private function guardCoverageStillExists(Plan $plan): void
    {
        if (! $plan->coverage_type->needsCourse()) {
            return;
        }

        $live = Course::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $plan->coverage_uuid)
            ->where('status', 'published')
            ->exists();

        if (! $live) {
            throw new DomainException('هذه الباقة غير متاحة.');
        }
    }

    private function coverageCourseId(Plan $plan): ?int
    {
        if (! $plan->coverage_type->needsCourse()) {
            return null;
        }

        $id = Course::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $plan->coverage_uuid)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
