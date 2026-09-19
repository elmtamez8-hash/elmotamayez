<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Support\PlanReach;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * The subscription plans one teacher offers (FR-025).
 *
 * ⚠️ THE TEACHER IS NAMED BY A COURSE, NOT BY A WORKSPACE UUID, and that is
 * about what the STUDENT actually holds. Every payload a student already has —
 * their balances, their enrolments — carries a course uuid and none of them
 * carries a workspace uuid, because the raw tenant key does not travel. Asking
 * for one would mean adding it to a student-facing Resource, which is the leak
 * `StudentBalanceAllowlist` exists to refuse. `/billing/packages?course=` takes
 * the same identifier for the same reason.
 *
 * ⚠️ AND IT IS A QUERY VALUE, NEVER A PATH PARAMETER. `/{course}` resolves the
 * model before any guard runs, and `BelongsToWorkspace` protects nothing on a
 * student's path: a student is a member of no workspace, `WorkspaceContext::id()`
 * is null, and `WorkspaceScope::apply()` adds no condition at all.
 *
 * ⚠️ AND THIS IS ONLY ONE THIRD OF THE CATALOGUE A STUDENT SEES. «بالحصّة» and
 * «بعدد من الحصص» are `credit_packages`, priced per course by
 * {@see ListCreditPackages}; time is what this table sells. The two lists are
 * assembled beside each other on the screen and stay apart in the API, because
 * one of them is derived from a teacher's settlement rate and guarded by
 * participation, and this one is a number an officer typed and guarded by
 * nothing.
 */
class ListPlans extends Action
{
    public function __construct(
        private readonly PlanReach $reach,
        private readonly CohortDirectory $cohorts,
    ) {}

    /**
     * @param  string|null  $sessionType  narrows the list to one room size (027 · FR-008)
     * @param  string|null  $cohortUuid  the group the buyer is standing on (036 · FR-015)
     * @return Collection<int, Plan>
     */
    public function handle(string $courseUuid, ?string $sessionType = null, ?string $cohortUuid = null): Collection
    {
        $workspaceId = Course::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $courseUuid)
            ->value('workspace_id');

        if ($workspaceId === null) {
            throw new DomainException('هذا الكورس غير موجود.');
        }

        $own = $this->ownPlansFor($cohortUuid, $courseUuid, (int) $workspaceId, $sessionType);

        if ($own !== null) {
            return $own;
        }

        return Plan::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->sellable()
            /*
            | ⚠️ THE WORKSPACE IS NOT THE COVERAGE, AND READING IT AS ONE OFFERED
            | EVERY PLAN ON EVERY COURSE THE TEACHER HAS. A plan scoped to «الكيمياء»
            | appeared on «التفاضل» — and the two modes failed differently, which
            | is why one filter has to close both:
            |
            |   • a GROUP choice was refused at the door with «هذه المجموعة لم تعد
            |     متاحة للانضمام» (`PurchaseSubscription::resolveCohort()` compares
            |     the group's course against the plan's coverage) — a sentence
            |     about the group, when the group was never the problem;
            |   • a PRIVATE choice was accepted. Nothing sends the course on that
            |     path — the plan's own coverage IS the course — so the buyer read
            |     «الكورس: التفاضل» on the screen and bought a month of «الكيمياء»,
            |     silently.
            |
            | Grouped, or the OR escapes `sellable()` and the workspace above it.
            */
            ->where(function ($query) use ($courseUuid): void {
                $query->where('coverage_type', PlanCoverage::Workspace->value)
                    ->orWhere(fn ($nested) => $nested
                        ->where('coverage_type', PlanCoverage::Course->value)
                        ->where('coverage_uuid', $courseUuid));
            })
            /*
            | ⚠️ DISPLAY, NOT PROTECTION. The one subscription screen shows only
            | the plans that match what the buyer chose, so a group choice is not
            | offered a one-to-one price — but the real guard is
            | `PurchaseSubscription::guardModeMatchesPlan()`, which sees the plan
            | the order is actually written against. A filter here alone would be
            | a rule enforced by a dropdown.
            */
            ->when($sessionType !== null, fn ($query) => $query->where('session_type', $sessionType))
            ->orderedByShape()
            ->get();
    }

    /**
     * The plans written FOR this group, or `null` when it has none of its own.
     *
     * ⛔ 036 · FR-015 · FR-016 — THE SCREEN NOW ANSWERS WHAT THE DOOR ANSWERS.
     * A teacher may price one group apart from the rest of its course; when they
     * have, that price REPLACES the course's rather than being offered beside it.
     * Until this, the replacement happened at `PurchaseSubscription` alone — so
     * the buyer was shown the course's month, picked it, and was refused with a
     * sentence about their group, which was never the problem.
     *
     * ⛔ AND THE GATE IS EXISTENCE, NOT SELLABILITY — which is why `naming()` is
     * asked before `sellableAmong()` rather than one `reaching()` call doing
     * both. A group whose own plan is written but not yet priced must show
     * NOTHING, not quietly fall back on its course: falling back is what makes
     * «باقتها بانتظار التسعير» a state no group can ever be in, and it would sell
     * the intensive group at the ordinary group's price in the meantime.
     *
     * ⚠️ AND IT CALLS {@see PlanReach}, WHICH IS THE SAME OBJECT THE LISTING GATE
     * CALLS. Re-spelling the coverage condition here is precisely the two-
     * spellings defect FR-016 exists to forbid — and the grouping parentheses
     * inside `covering()` are load-bearing enough that a second copy would
     * eventually lose them and publish every plan on the platform.
     *
     * ⚠️ A GROUP THAT DOES NOT RESOLVE INTO THIS COURSE IS IGNORED, NOT REFUSED.
     * This is a display read reached from a bookmarked address; throwing here
     * would blank the purchase screen over a stale uuid. The door still refuses
     * that group, with its own sentence about the group.
     *
     * @return Collection<int, Plan>|null
     */
    private function ownPlansFor(?string $cohortUuid, string $courseUuid, int $workspaceId, ?string $sessionType): ?Collection
    {
        if ($cohortUuid === null) {
            return null;
        }

        $cohort = $this->cohorts->describeGroupCohort($cohortUuid);

        if ($cohort === null
            || (int) $cohort['workspace_id'] !== $workspaceId
            || $cohort['course_uuid'] !== $courseUuid) {
            return null;
        }

        $ownIds = $this->reach->ownPlanIds($workspaceId, $cohortUuid);

        if ($ownIds === []) {
            return null;
        }

        return $this->reach
            ->sellableAmong($ownIds)
            ->when($sessionType !== null, fn ($query) => $query->where('session_type', $sessionType))
            ->orderedByShape()
            ->get();
    }
}
