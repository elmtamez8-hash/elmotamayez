<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Contracts\CohortPricingReasonDirectory;
use App\Shared\Contracts\SellableCohortDirectory;
use App\Shared\Support\CohortPricingGap;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The cohort bridge, on the `Payments` side (٠٣٦ · T019 · FR-003 · FR-014).
 *
 * ⛔ THE OVERRULE RULE IS THE WHOLE OF THIS CLASS, AND ITS SECOND CONDITION IS
 * THE ONE THAT LOOKS REDUNDANT AND IS NOT. A group is listed when:
 *
 *   a plan of its own that can be bought — **or**, and only when **no plan of
 *   its own exists at all**, a plan of its course or of the whole workspace that
 *   can be bought.
 *
 * Written as «own-sellable OR (no own-sellable AND inherited-sellable)» the
 * second clause is literally the first negated, and the whole thing collapses to
 * «own OR inherited» — at which point a group whose own plan is sitting unpriced
 * quietly inherits its course's price, is listed, and «باقتها بانتظار التسعير»
 * becomes a sentence no group on the platform can ever be in. The gate is
 * EXISTENCE, not sellability, which is why {@see PlanReach::naming()} carries no
 * `sellable()` and no session-type filter.
 *
 * ⚠️ FOUR QUERIES FOR ANY NUMBER OF GROUPS, AND THE COUNT DOES NOT GROW. A
 * Resource runs once per row; both contracts this implements are bulk by
 * signature for that reason, and the matching below happens in PHP over four
 * result sets rather than in a loop of `exists()`.
 *
 * ⚠️ AND EVERY MATCH PINS THE WORKSPACE FROM THE CANDIDATE ITSELF. `plans`
 * carries the tenant scope and {@see PlanReach} lifts it deliberately, so the
 * pin is the only thing left standing between one teacher's blanket plan and
 * another teacher's group. It cannot come from a join — `cohorts` belongs to
 * `Learning` and this module may not name it — which is exactly why the caller
 * hands over triples rather than ids.
 *
 * ⚠️ THE ROOM SIZE IS PART OF THE QUESTION. A public group is sold by a `group`
 * plan; an `individual` one priced for a one-to-one hour does not list it. Without
 * that condition a group is offered, the buyer picks it, and the purchase door
 * refuses the plan for a room size the screen never mentioned — SC-007 broken
 * from inside the gate that exists to keep it.
 */
class CohortPlanReach implements CohortPricingReasonDirectory, SellableCohortDirectory
{
    public function __construct(private readonly PlanReach $reach) {}

    /**
     * {@inheritDoc}
     */
    public function sellableCohortIds(array $cohorts): array
    {
        $listed = [];

        foreach ($this->verdicts($cohorts) as $cohortId => $gap) {
            if ($gap === null) {
                $listed[] = $cohortId;
            }
        }

        return $listed;
    }

    /**
     * {@inheritDoc}
     */
    public function pricingGapsFor(array $cohorts): array
    {
        return array_filter($this->verdicts($cohorts), fn (?CohortPricingGap $gap): bool => $gap !== null);
    }

    /**
     * One verdict per group: `null` when it is listed, otherwise why it is not.
     *
     * ⚠️ ONE WALK FEEDING BOTH CONTRACTS. The listing answer and the teacher's
     * reason are the same three reads asked once; two implementations would be
     * two spellings of the overrule rule, and they would disagree on the day
     * somebody changes one — which is the divergence this whole bridge exists to
     * prevent.
     *
     * @param  list<array{id: int, uuid: string, course_id: int, workspace_id: int}>  $cohorts
     * @return array<int, CohortPricingGap|null> keyed by cohort id
     */
    private function verdicts(array $cohorts): array
    {
        if ($cohorts === []) {
            return [];
        }

        $workspaceIds = $this->uniqueInts(array_column($cohorts, 'workspace_id'));
        $cohortUuids = $this->uniqueStrings(array_column($cohorts, 'uuid'));
        $courseUuids = $this->courseUuids($this->uniqueInts(array_column($cohorts, 'course_id')), $workspaceIds);

        $anchors = $this->uniqueStrings(array_merge($cohortUuids, array_values($courseUuids)));

        /*
        | ⚠️ ONE QUERY WITH THE ROWS, AND A SECOND THAT NARROWS **THOSE ROWS** TO
        | THE ONES A BUYER CAN BUY. Deriving «sellable» in PHP from `price_minor`
        | and `is_active` would be a second spelling of `Plan::scopeSellable()`,
        | which is the defect the rest of this file is about — and re-asking the
        | coverage question with `sellable()` bolted on would be a second
        | spelling of the ANCHORS AND THE ROOM SIZE. **Measured: with the
        | condition written at both call sites, mutating either one alone left
        | the room-size test green, because the other still refused the row.**
        | Narrowing by id leaves exactly one place where `group` is said.
        */
        $covering = $this->reach
            ->covering($workspaceIds, $anchors, ClassSessionType::Group->value)
            ->get(['id', 'workspace_id', 'coverage_type', 'coverage_uuid', 'price_minor', 'is_active']);

        $sellableIds = array_flip($this->uniqueInts(
            $this->reach
                ->sellableAmong($this->uniqueInts($covering->modelKeys()))
                ->pluck('id')
                ->all()
        ));

        $named = $this->reach
            ->naming($workspaceIds, $cohortUuids)
            ->get(['workspace_id', 'coverage_uuid']);

        $verdicts = [];

        foreach ($cohorts as $cohort) {
            $workspaceId = (int) $cohort['workspace_id'];
            $uuid = (string) $cohort['uuid'];
            $courseUuid = $courseUuids[$workspaceId.':'.(int) $cohort['course_id']] ?? null;

            $own = $covering->filter(fn (Plan $plan): bool => (int) $plan->workspace_id === $workspaceId
                && $plan->coverage_uuid === $uuid);

            if ($this->anySellable($own, $sellableIds)) {
                $verdicts[(int) $cohort['id']] = null;

                continue;
            }

            $hasOwnPlan = $named->contains(fn (Plan $plan): bool => (int) $plan->workspace_id === $workspaceId
                && $plan->coverage_uuid === $uuid);

            if ($hasOwnPlan) {
                /*
                | The group is priced separately, badly. It does NOT fall back on
                | its course — that fallback is what would make «awaiting
                | pricing» unreachable — so the reason comes from its own rows.
                |
                | ⚠️ AND THOSE ROWS CAN BE EMPTY WHILE A PLAN EXISTS: a plan
                | named for this group but written for a one-to-one room is
                | filtered out above by the room size and reads as «no plan».
                | That is the honest answer — no group-shaped price covers it —
                | and `SavePlan` refuses to write the combination at all now, so
                | it can only be a row older than that guard.
                */
                $verdicts[(int) $cohort['id']] = $this->gapFrom($own);

                continue;
            }

            $inherited = $covering->filter(fn (Plan $plan): bool => (int) $plan->workspace_id === $workspaceId
                && ($plan->coverage_type === PlanCoverage::Workspace
                    || ($courseUuid !== null && $plan->coverage_uuid === $courseUuid)));

            $verdicts[(int) $cohort['id']] = $this->anySellable($inherited, $sellableIds)
                ? null
                : $this->gapFrom($inherited);
        }

        return $verdicts;
    }

    /**
     * @param  EloquentCollection<int, Plan>  $plans
     * @param  array<int, int>  $sellableIds
     */
    private function anySellable(EloquentCollection $plans, array $sellableIds): bool
    {
        return $plans->contains(fn (Plan $plan): bool => isset($sellableIds[(int) $plan->getKey()]));
    }

    /**
     * Why none of these plans lists the group.
     *
     * ⚠️ THE ACTIONABLE REASON WINS. A group held back by a plan the teacher can
     * switch on is told so, even when another plan of theirs is also waiting on
     * the platform's price: one of the two answers ends with something they can
     * do this minute, and the other ends with «wait». Ordering it the other way
     * sends a teacher who has a remedy off to wait for one they do not need.
     *
     * @param  EloquentCollection<int, Plan>  $plans
     */
    private function gapFrom(EloquentCollection $plans): CohortPricingGap
    {
        if ($plans->isEmpty()) {
            return CohortPricingGap::NoPlan;
        }

        if ($plans->contains(fn (Plan $plan): bool => $plan->price_minor !== null && ! $plan->is_active)) {
            return CohortPricingGap::Disabled;
        }

        if ($plans->contains(fn (Plan $plan): bool => $plan->price_minor === null)) {
            return CohortPricingGap::AwaitingPricing;
        }

        return CohortPricingGap::NoPlan;
    }

    /**
     * The public identifier of each of these courses, keyed by id.
     *
     * ⚠️ THE WORKSPACE IS PART OF THE READ, NOT A COURTESY. `courses` carries
     * the tenant scope and this is read from a public page where the context is
     * null and the scope is therefore inert — so the pin has to be written out.
     * A course outside every candidate's workspace simply has no uuid here, and
     * the group that named it inherits nothing rather than inheriting somebody
     * else's price.
     *
     * ⚠️ AND THE KEY CARRIES THE WORKSPACE WITH IT. A bulk call may span
     * teachers, and a map keyed by course id alone would let a group of one
     * workspace read the uuid of a course belonging to another — the very pin
     * this method exists to write, undone by the shape of its own return value.
     *
     * @param  list<int>  $courseIds
     * @param  list<int>  $workspaceIds
     * @return array<string, string> keyed by `workspaceId:courseId`
     */
    private function courseUuids(array $courseIds, array $workspaceIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $uuids = [];

        foreach (Course::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $courseIds)
            ->whereIn('workspace_id', $workspaceIds)
            ->get(['id', 'uuid', 'workspace_id']) as $course) {
            $uuids[$course->workspace_id.':'.$course->getKey()] = (string) $course->uuid;
        }

        return $uuids;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function uniqueInts(array $values): array
    {
        return array_values(array_unique(array_map(intval(...), $values)));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_map(strval(...), $values)));
    }
}
