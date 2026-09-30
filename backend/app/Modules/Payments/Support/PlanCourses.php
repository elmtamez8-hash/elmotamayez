<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Contracts\CohortDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which course a plan sells — the one question the assistant scope (spec 010 ·
 * FR-005) asks of it, spelled once for the doors and once for the list.
 *
 * A course plan is its course; a group plan is its group's course. ⚠️ A
 * WORKSPACE PLAN HAS NO COURSE, and `AssistantScopeDirectory::mayActOnCourse()`
 * refuses that `null` to a confined assistant: a plan over everything the
 * teacher publishes is not work on one course, and reading it as «no
 * restriction» is the hole the directory's null rule exists to close.
 *
 * ⚠️ EVERY READ NAMES THE WORKSPACE. A plan's `coverage_uuid` is a bare uuid,
 * and resolving it outside the plan's workspace would answer about another
 * teacher's course.
 */
final class PlanCourses
{
    public function __construct(private readonly CohortDirectory $cohorts) {}

    public function of(PlanCoverage $coverage, ?string $uuid, int $workspaceId): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        if ($coverage === PlanCoverage::Course) {
            $id = Course::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->where('uuid', $uuid)
                ->value('id');

            return $id === null ? null : (int) $id;
        }

        if ($coverage === PlanCoverage::Cohort) {
            $cohort = $this->cohorts->describeGroupCohort($uuid);

            return $cohort !== null && (int) $cohort['workspace_id'] === $workspaceId
                ? (int) $cohort['course_id']
                : null;
        }

        return null;
    }

    public function ofPlan(Plan $plan): ?int
    {
        return $this->of($plan->coverage_type, $plan->coverage_uuid, (int) $plan->workspace_id);
    }

    /**
     * Narrow a list to the plans selling one of these courses — the list form
     * of {@see of()}. One read for the courses' uuids and one per course for its
     * groups; a confinement is a handful of courses.
     *
     * @param  Builder<Plan>  $query
     * @param  list<int>  $courseIds
     * @return Builder<Plan>
     */
    public function within(Builder $query, array $courseIds, int $workspaceId): Builder
    {
        $courseUuids = Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $courseIds)
            ->pluck('uuid')
            ->map(fn (mixed $uuid): string => (string) $uuid)
            ->all();

        $cohortUuids = [];

        foreach ($courseIds as $courseId) {
            foreach ($this->cohorts->cohortUuidsFor($courseId) as $uuid) {
                $cohortUuids[] = $uuid;
            }
        }

        return $query->where(fn (Builder $any) => $any
            ->where(fn (Builder $course) => $course
                ->where('coverage_type', PlanCoverage::Course->value)
                ->whereIn('coverage_uuid', $courseUuids))
            ->orWhere(fn (Builder $cohort) => $cohort
                ->where('coverage_type', PlanCoverage::Cohort->value)
                ->whereIn('coverage_uuid', $cohortUuids)));
    }
}
