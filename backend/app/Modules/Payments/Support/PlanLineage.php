<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\PlanChangeRequest;

/**
 * Which plans are «the same plan» for a renewal — moved out of
 * `ActivateSubscription` so the freeze recompute can walk the same chain a
 * renewal was dated along (see `EffectiveSubscriptionEnd`). Two copies of this
 * walk would chain a renewal one way at approval and re-date it another way at
 * the next freeze.
 */
class PlanLineage
{
    /**
     * This plan and every plan it replaced — the chain a renewal must extend along.
     *
     * ⛔ WITHOUT IT THE EXTENSION STOPS AT THE FIRST PRICE CHANGE, AND THAT IS THE
     * MOST ORDINARY THING A TEACHER DOES. `DecidePlanChange` does not edit a plan;
     * it writes a NEW row and retires the old one, so a student holding a running
     * month renews onto a different `plan_id` and the lookup in {@see claim()}
     * finds nothing — restoring the very defect it was added to end, silently, for
     * every teacher who has ever repriced.
     *
     * ⚠️ `approved_plan_id` IS THE ONLY THREAD, as `DecidePlanChange`'s own header
     * says. There is no `replaces_plan_id` on `plans` and no coverage-based
     * shortcut: `plans.coverage_uuid` is NULL for a workspace-coverage plan and
     * `NULL = NULL` is never true, so matching «the same coverage» would chain
     * course plans and silently never chain workspace ones — this repository's
     * most-repeated defect, reached from a new direction.
     *
     * ⚠️ AND THE WALK IS BOUNDED AND CYCLE-AWARE. This runs in a queue worker on
     * rows an operator writes; an unbounded walk over a cycle is a hung worker and
     * a payment approved with no subscription behind it. Twenty hops is already
     * pathological — a plan repriced twenty times — and the cost of stopping early
     * is the old behaviour, not a wrong one.
     *
     * @return list<int>
     */
    public function of(Plan $plan): array
    {
        $ids = [(int) $plan->getKey()];

        for ($hop = 0; $hop < 20; $hop++) {
            $previous = PlanChangeRequest::query()
                ->withoutWorkspaceScope()
                ->where('approved_plan_id', $ids[count($ids) - 1])
                ->value('plan_id');

            if ($previous === null || in_array((int) $previous, $ids, true)) {
                break;
            }

            $ids[] = (int) $previous;
        }

        return $ids;
    }
}
