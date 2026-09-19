<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Which plans actually reach a buyer here?» — written once (٠٣٦ · T020).
 *
 * ⛔ A THIRD CLASS THAT KNOWS NO DIRECTORY, AND THAT IS NOT TIDINESS. The rule
 * is asked by {@see SubscriptionEligibility::hasSellablePlanFor()} — which IS
 * the `SubscriptionDirectory` — and by {@see CohortPlanReach}, which is the
 * cohort bridge. Written `private` inside either of them, the other has to
 * inject it, and the two directories then depend on each other: **a container
 * cycle measured on this very tree, a memory collapse at roughly 147,000 frames
 * inside `Container::build()` with no message naming a class, and neither
 * `bind` nor `scoped` nor `singleton` saves you from it.** A class with no
 * constructor and no dependency cannot be part of a cycle.
 *
 * ⚠️ AND IT HAS NO CONSTRUCTOR ON PURPOSE. Give it one dependency and the
 * paragraph above stops being true.
 */
class PlanReach
{
    /**
     * Every plan of these teachers whose coverage reaches one of these anchors
     * — the group itself, its course, or the whole workspace — **whether or not
     * it can be bought**.
     *
     * ⚠️ THE GROUPING PARENTHESES ARE LOAD-BEARING, and they are the reason this
     * is one function rather than a condition repeated at two call sites.
     * Written flat, the `orWhere` ORs at the TOP level and throws away the
     * workspace condition with it — publishing every plan on the platform as
     * though it covered this course. The comment that said so used to sit at the
     * only call site; it now sits at the only spelling.
     *
     * ⚠️ THE SESSION TYPE IS AN ARGUMENT AND IS NEVER BAKED IN. The cohort
     * bridge passes `group`, and `ReadPublicCourse` passes `individual` for the
     * private-subscription invitation on every public course page — a condition
     * fixed at `group` inside here would make that call ask for a plan that is
     * both, i.e. impossible, and the invitation would vanish from every course
     * page on the platform in silence.
     *
     * ⚠️ AND `withoutWorkspaceScope()` IS REQUIRED, NOT DEFENSIVE. `plans`
     * carries the tenant scope; this is read on a public page where the context
     * is null (inert) AND by a signed-in teacher from another workspace, where
     * it bites and hides every row. The caller pins the workspace instead — with
     * ids it was handed, never with a join.
     *
     * @param  list<int>  $workspaceIds
     * @param  list<string>  $coverageUuids  public uuids a narrow plan may name
     * @return Builder<Plan>
     */
    public function covering(array $workspaceIds, array $coverageUuids, ?string $sessionType = null): Builder
    {
        $query = Plan::query()
            ->withoutWorkspaceScope()
            ->whereIn('workspace_id', $workspaceIds)
            ->where(fn (Builder $inner) => $inner
                ->where('coverage_type', PlanCoverage::Workspace->value)
                ->orWhereIn('coverage_uuid', $coverageUuids));

        if ($sessionType !== null) {
            $query->where('session_type', $sessionType);
        }

        return $query;
    }

    /**
     * The same, narrowed to what a buyer can actually buy.
     *
     * ⚠️ `sellable()` IS THE MODEL'S OWN SCOPE, NEVER A PARALLEL CONDITION.
     * «Priced and switched on» has one definition and it lives on `Plan`; a
     * second one written out here — or in PHP over the rows of
     * {@see self::covering()} — is the two-spellings defect this repository
     * records a dozen times over.
     *
     * @param  list<int>  $workspaceIds
     * @param  list<string>  $coverageUuids
     * @return Builder<Plan>
     */
    public function reaching(array $workspaceIds, array $coverageUuids, ?string $sessionType = null): Builder
    {
        return $this->covering($workspaceIds, $coverageUuids, $sessionType)->sellable();
    }

    /**
     * Which of these plans, already chosen, a buyer can actually buy.
     *
     * ⛔ IT NARROWS AN EXISTING SET RATHER THAN RE-ASKING THE COVERAGE QUESTION,
     * and that is the whole reason it exists beside {@see self::covering()}. A
     * caller that needs both «everything that covers this» and «which of those
     * is live» used to issue the coverage query twice, once with `sellable()` —
     * which meant the room size and the anchors were written at two call sites.
     * They then agreed until somebody changed one, and a mutation of either
     * alone was invisible because the other still refused the row: **measured,
     * on this file's own test.**
     *
     * @param  list<int>  $planIds
     * @return Builder<Plan>
     */
    public function sellableAmong(array $planIds): Builder
    {
        return Plan::query()
            ->withoutWorkspaceScope()
            ->whereKey($planIds)
            ->sellable();
    }

    /**
     * The ids of every plan written FOR this group -- priced or not, any room size.
     *
     * ⛔ 036 · FR-016 -- THE ONE SPELLING THE SCREEN AND THE DOOR BOTH READ, and
     * that is the requirement rather than a convenience. `ListPlans` uses it to
     * show the group's own price INSTEAD of its course's; `PurchaseSubscription`
     * uses it to refuse the course's price on a group that was priced apart.
     * Spelled twice, they disagree the first time one of them moves -- and the
     * shape of that disagreement is a plan that is listed and then refused, with
     * a sentence about the group, which was never the problem.
     *
     * ⚠️ EXISTENCE, NOT SELLABILITY -- see {@see self::naming()}. A group whose
     * own plan is written and unpriced has stopped inheriting: it shows nothing
     * and buys nothing, rather than quietly being sold its course's price.
     *
     * @return list<int>
     */
    public function ownPlanIds(int $workspaceId, string $cohortUuid): array
    {
        return array_values(array_map(
            intval(...),
            $this->naming([$workspaceId], [$cohortUuid])->pluck('id')->all(),
        ));
    }

    /**
     * Plans that NAME one of these anchors — wide coverage excluded, sellable or
     * not, of any room size.
     *
     * ⛔ A DIFFERENT QUESTION FROM {@see self::reaching()}, and the difference is
     * the whole of the overrule rule. «Is this group covered by something
     * buyable» and «has anybody written a plan FOR this group» diverge on
     * exactly the row that matters: a plan of its own that the platform has not
     * priced yet. Answered with the first, such a group quietly inherits its
     * course's price and is listed — so «باقتها بانتظار التسعير» becomes a
     * sentence no group can ever be in, and FR-014's whole middle case is
     * unreachable.
     *
     * ⚠️ AND IT IS DELIBERATELY NOT FILTERED BY SESSION TYPE. A plan written for
     * this group is a plan written for this group; refusing to see one because
     * its room size is wrong would let the group inherit anyway, which is the
     * failure above wearing a different cause. `SavePlan` refuses to write that
     * combination at all now, so it can only be a row older than that guard.
     *
     * @param  list<int>  $workspaceIds
     * @param  list<string>  $coverageUuids
     * @return Builder<Plan>
     */
    public function naming(array $workspaceIds, array $coverageUuids): Builder
    {
        return Plan::query()
            ->withoutWorkspaceScope()
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('coverage_uuid', $coverageUuids);
    }
}
