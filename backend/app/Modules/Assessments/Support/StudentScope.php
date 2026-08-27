<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a STUDENT may be shown from a workspace-owned list.
 *
 * ⚠️ THIS CLOSES A CROSS-TENANT LEAK, AND THE LEAK WAS THE ABSENCE OF ANY
 * CONDITION AT ALL. `WorkspaceScope::apply()` adds nothing when the context is
 * null, and the context is ALWAYS null for a student: nothing on their path
 * writes `users.last_workspace_id` — enrolling writes nothing and signing in
 * writes nothing, and its only writers are `CreateWorkspace` and
 * `WorkspaceContext::set()`, both about workspace MEMBERS. So `GET /exams` and
 * `GET /assignments` answered any signed-in student with every published paper
 * and every published deadline on the PLATFORM — other teachers' titles,
 * descriptions, due dates and point values. Measured, not reasoned: a fixture
 * with two workspaces returned both.
 *
 * ⚠️ AND IT IS TWO PREDICATES, BECAUSE `course_id` IS NULLABLE ON BOTH TABLES BY
 * DESIGN. `SaveAssignmentRequest` allows a null course explicitly — a teacher
 * may set one paper for all their students — so narrowing by course alone would
 * hide every course-less item from exactly the people it was written for, which
 * is the mirror-image defect and just as silent. The second arm is the workspace
 * the student actually studies in, which only an enrolment can say.
 *
 * ⚠️ THE GROUPING IS LOAD-BEARING. Written flat, the `orWhere` would sit beside
 * whatever the caller added before it — the `published` filter, the status
 * disjunction — and an un-parenthesised OR beside a filter is how the same
 * `ExamController` was already leaking one course's papers into another's list.
 *
 * ⚠️ AND AN EMPTY LIST MEANS NOTHING IS VISIBLE, NEVER EVERYTHING. A person with
 * no enrolment at all gets `whereIn('course_id', [])`, which matches no row —
 * the direction a filter must fail in.
 *
 * ⚠️ IT REPLACES `WorkspaceScope`, IT DOES NOT STACK ON TOP OF IT — which is why
 * {@see applyIfUnscoped()} is what the controllers call. A reader who HAS a
 * workspace context is already narrowed correctly and there is no leak to close
 * for them; narrowing them again would be an entitlement change wearing a
 * security fix's clothes. `StartAttempt::enrollmentFor()` returns NULL when the
 * exam has no course and writes the attempt anyway, so an invited workspace
 * member with no enrolment may genuinely sit a published paper — hiding it from
 * them would be offering nothing where the server would have said yes, which is
 * this repository's two-answers defect in its other direction.
 */
class StudentScope
{
    /**
     * Narrow only when nothing else is narrowing — the hole, and only the hole.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyIfUnscoped(Builder $query, User $user, EnrollmentDirectory $enrollments): Builder
    {
        if (app(WorkspaceContext::class)->id() !== null) {
            return $query;
        }

        return self::apply($query, $user, $enrollments);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, User $user, EnrollmentDirectory $enrollments): Builder
    {
        $courseIds = $enrollments->activeCourseIdsFor($user);
        $workspaceIds = $enrollments->activeWorkspaceIdsFor($user);

        return $query->where(fn (Builder $scoped): Builder => $scoped
            ->whereIn('course_id', $courseIds)
            ->orWhere(fn (Builder $general): Builder => $general
                ->whereNull('course_id')
                ->whereIn('workspace_id', $workspaceIds)));
    }
}
