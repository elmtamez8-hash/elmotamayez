<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Contracts\CohortDirectory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * «Which course does this plan open?» — asked in ONE place (٠٣٦ · T009).
 *
 * | coverage  | opens                                   |
 * |-----------|-----------------------------------------|
 * | workspace | every published course of that teacher  |
 * | course    | that course                             |
 * | cohort    | **that group's course, and nothing else** |
 *
 * ⛔ WHY IT IS A CLASS AND NOT A METHOD ON THE PLAN. The question had four
 * spellings before this, and the third coverage broke every one of them in a
 * different direction: `ActivateSubscription::coveredCourses()` would have
 * enrolled the buyer of a group plan in EVERY published course the teacher has,
 * `SubscriptionEligibility::reaches()` returned `$plan !== null` — a group
 * subscription covering every course for its whole life — and both
 * `PurchaseSubscription::guardCoverageStillExists()` and its `coverageCourseId()`
 * looked the uuid up in `courses`, where a cohort uuid matches zero rows, so
 * every group plan was refused. Four readers, four different wrong answers, none
 * of them an error anybody could see.
 *
 * ⚠️ IT ANSWERS IN THE SHAPE EACH CALLER NEEDS, which is why there are three
 * methods rather than one «list of ids» everybody converts. `coverageCourseId()`
 * needs NULL for workspace coverage — not a list of twelve ids — because it
 * writes one column; `coveredCourses()` needs MODELS, because it enrols; and the
 * uuid form is what an eligibility read compares against without a second query.
 *
 * ⚠️ AND A COHORT UUID IS RESOLVED THROUGH THE DIRECTORY, NEVER BY QUERYING
 * `cohorts`. That table belongs to `Learning`, and `ContextIsolationTest` fails
 * the build over a Payments file that names it. `describeGroupCohort()` is also
 * already filtered to GROUP cohorts, which is the guard that stops a plan being
 * pointed at somebody's private 1:1 room.
 */
class CoveredCourses
{
    public function __construct(private readonly CohortDirectory $cohorts) {}

    /**
     * The published courses this plan opens, as models.
     *
     * @return EloquentCollection<int, Course>
     */
    public function coveredCourses(Plan $plan): EloquentCollection
    {
        $base = Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $plan->workspace_id)
            ->where('status', 'published');

        if ($plan->coverage_type === PlanCoverage::Workspace) {
            return $base->get();
        }

        $uuid = $this->courseUuid($plan);

        if ($uuid === null) {
            /*
             * A plan whose coverage no longer resolves — the course deleted, the
             * group archived — opens nothing. An empty collection rather than a
             * throw, because this runs inside activation AFTER the money has
             * committed: the subscription stays, and its access simply reaches no
             * course, exactly as an unpublished course already falls out of
             * coverage without cancelling anything.
             */
            return new EloquentCollection;
        }

        return $base->where('uuid', $uuid)->get();
    }

    /**
     * The one course this plan names, or `null` when it names a whole workspace.
     *
     * Null is «not a single course», never «not found» — a caller writing
     * `orders.course_id` wants exactly that distinction.
     */
    public function coverageCourseId(Plan $plan): ?int
    {
        if ($plan->coverage_type === PlanCoverage::Workspace) {
            return null;
        }

        $uuid = $this->courseUuid($plan);

        if ($uuid === null) {
            return null;
        }

        $course = Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $plan->workspace_id)
            ->where('uuid', $uuid)
            ->first(['id']);

        return $course === null ? null : (int) $course->getKey();
    }

    /**
     * The uuid of the course this plan names, or `null` for workspace coverage
     * and for a coverage that no longer resolves.
     *
     * ⚠️ THE TWO NULLS ARE DELIBERATELY NOT DISTINGUISHED HERE. Every caller
     * treats «this plan does not name one course» and «the course it named is
     * gone» the same way — the first because there is nothing to compare, the
     * second because a coverage that resolves to nothing covers nothing. A caller
     * that ever needs to tell them apart should ask a new method rather than
     * reading a sentinel out of this one.
     */
    public function courseUuid(Plan $plan): ?string
    {
        return match ($plan->coverage_type) {
            PlanCoverage::Workspace => null,
            PlanCoverage::Course => $plan->coverage_uuid,
            PlanCoverage::Cohort => $this->cohortCourseUuid($plan),
        };
    }

    private function cohortCourseUuid(Plan $plan): ?string
    {
        if ($plan->coverage_uuid === null) {
            return null;
        }

        $cohort = $this->cohorts->describeGroupCohort($plan->coverage_uuid);

        if ($cohort === null) {
            return null;
        }

        /*
         * ⚠️ AND THE WORKSPACE IS PINNED AGAIN HERE. `describeGroupCohort()` is
         * deliberately unscoped — it must resolve another teacher's group in order
         * to refuse it — so without this a plan of teacher A pointed at a uuid of
         * teacher B's group would open B's course to A's buyer. The directory
         * hands the workspace back precisely so the caller can prove it.
         */
        if ((int) $cohort['workspace_id'] !== (int) $plan->workspace_id) {
            return null;
        }

        return $cohort['course_uuid'];
    }
}
