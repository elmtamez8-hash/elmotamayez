<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
     * The same question about ONE row — for a policy, which has no query to narrow.
     *
     * ⚠️ THE LIST WAS CLOSED AND THE DOOR WAS NOT, WHICH IS THIS REPOSITORY'S
     * TWO-SPELLINGS DEFECT IN ITS USUAL DIRECTION. `applyIfUnscoped()` above
     * stopped `GET /exams` answering every published paper on the platform —
     * while `ExamPolicy::view()` and `AssignmentPolicy::view()` still read
     * "published ⇒ allow", with `belongsToCurrentWorkspace()` correctly raising
     * no objection on the null context every student has. So a student who knew
     * a uuid opened another course's paper and SAT it (`StartAttempt` asks for no
     * enrolment and writes a nullable `enrollment_id`), and posted homework into
     * a stranger's marking queue. Measured 2026-09-05.
     *
     * ⚠️ IT IS A METHOD ON THIS CLASS AND NOT A CONDITION COPIED INTO THE POLICY,
     * because two spellings of one entitlement is exactly how the gap opened.
     *
     * ⚠️ AND IT ANSWERS TRUE FOR A RESOLVED CONTEXT, mirroring
     * {@see applyIfUnscoped()} line for line: a workspace member is already
     * narrowed by the scope and by the check above this call, and an invited
     * member with no enrolment may genuinely sit a course-less paper.
     */
    public static function permits(Model $record, User $user, EnrollmentDirectory $enrollments): bool
    {
        if (app(WorkspaceContext::class)->id() !== null) {
            return true;
        }

        $courseId = $record->getAttribute('course_id');

        // Nullable on both tables by design — a paper set for every student of a
        // workspace rather than for one course. Narrowing that by course alone
        // would hide it from exactly the people it was written for.
        if ($courseId !== null) {
            return in_array((int) $courseId, array_map('intval', $enrollments->activeCourseIdsFor($user)), true);
        }

        return in_array(
            (int) $record->getAttribute('workspace_id'),
            array_map('intval', $enrollments->activeWorkspaceIdsFor($user)),
            true,
        );
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
