<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\EnrollmentDirectory;

/**
 * The only implementation of {@see AssistantScopeDirectory}.
 *
 * ⚠️ MEMOISED PER REQUEST, AND THAT IS NOT AN OPTIMISATION. `isAssistantIn()` is
 * the key of the financial wall's `Gate::before`, which Laravel calls on EVERY
 * permission check — dozens of times on one Filament page, and once per row in
 * any list that asks a policy. `PlatformStaffDirectory` is memoised for exactly
 * this reason and this class copies it.
 *
 * ⚠️ AND EVERY READ DECLARES `withoutWorkspaceScope()` WITH AN EXPLICIT
 * `workspace_id`. The caller names the workspace it is asking about, and the
 * ambient context is not always that one: `WorkspaceContext::id()` falls back to
 * `users.last_workspace_id` for everybody including a super admin, and it is null
 * for a student — who is a member of no workspace at all. Left scoped, the same
 * question would answer differently depending on who happened to be signed in.
 */
final class EloquentAssistantScopeDirectory implements AssistantScopeDirectory
{
    /**
     * Assignment id per `{user}:{workspace}`, or `null` for "not an assistant".
     *
     * @var array<string, int|null>
     */
    private array $assignments = [];

    /**
     * Scoped course ids per `{user}:{workspace}`, or `null` for "not confined".
     *
     * @var array<string, list<int>|null>
     */
    private array $scopes = [];

    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    public function isAssistantIn(User $user, int $workspaceId): bool
    {
        return $this->assignmentId($user, $workspaceId) !== null;
    }

    public function mayActOnCourse(User $user, int $workspaceId, ?int $courseId): bool
    {
        $scoped = $this->scopedCourseIdsFor($user, $workspaceId);

        if ($scoped === null) {
            return true;
        }

        // ⚠️ A CONFINED ASSISTANT IS REFUSED WORK THAT HANGS OFF NO COURSE. An
        // exam set for the workspace at large has no course to compare against,
        // and reading that as "no restriction applies" is a hole shaped exactly
        // like the confinement — reachable by leaving the course field empty.
        return $courseId !== null && in_array($courseId, $scoped, true);
    }

    public function mayActOnStudent(User $user, int $workspaceId, int $studentUserId): bool
    {
        $scoped = $this->scopedCourseIdsFor($user, $workspaceId);

        if ($scoped === null) {
            return true;
        }

        $student = User::query()->find($studentUserId);

        if (! $student instanceof User) {
            return false;
        }

        /*
        | ⚠️ THE INTERSECTION IS SAFE ACROSS WORKSPACES BECAUSE THE SCOPE IS NOT.
        | `activeCourseIdsFor()` answers for every workspace the student studies
        | in, but every id in `$scoped` belongs to THIS workspace by construction
        | — so the intersection can only contain courses that are both inside the
        | assistant's confinement and live for the student.
        */
        return array_intersect($scoped, $this->enrollments->activeCourseIdsFor($student)) !== [];
    }

    public function scopedCourseIdsFor(User $user, int $workspaceId): ?array
    {
        $key = $this->key($user, $workspaceId);

        if (array_key_exists($key, $this->scopes)) {
            return $this->scopes[$key];
        }

        $assignmentId = $this->assignmentId($user, $workspaceId);

        if ($assignmentId === null) {
            return $this->scopes[$key] = null;
        }

        /*
        | ⚠️ READ THROUGH THE ASSIGNMENT ID, WHICH IS THE ONLY GUARD
        | `assistant_scopes` HAS. The table carries no `workspace_id` and no
        | global scope, so a query that did not start from an assignment already
        | proved to be in this workspace would read every teacher's confinements.
        */
        $rows = AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->whereKey($assignmentId)
            ->firstOrFail()
            ->scopes()
            ->pluck('course_id');

        $courseIds = [];

        foreach ($rows as $courseId) {
            $courseIds[] = (int) $courseId;
        }

        // ⚠️ An empty scope is NO CONFINEMENT, never "no courses" — the whole
        // reason there is no "all courses" column. Returning `[]` here would
        // refuse an assistant everything the moment they were invited.
        return $this->scopes[$key] = $courseIds === [] ? null : $courseIds;
    }

    public function scopedStudentIdsFor(User $user, int $workspaceId): ?array
    {
        $scoped = $this->scopedCourseIdsFor($user, $workspaceId);

        if ($scoped === null) {
            return null;
        }

        /*
        | One read per scoped course, through the one spelling of «actively
        | enrolled» the announcement fan-out uses — the same `GRANTING_STATUSES`
        | predicate `activeCourseIdsFor()` reads for `mayActOnStudent()`, so a
        | student on this list is a student that door lets through. A confinement
        | is a handful of courses, never hundreds.
        */
        $ids = [];

        foreach ($scoped as $courseId) {
            foreach ($this->enrollments->activeStudentIdsFor($workspaceId, $courseId) as $studentId) {
                $ids[$studentId] = $studentId;
            }
        }

        ksort($ids);

        return array_values($ids);
    }

    public function whoMayActOnStudent(array $userIds, int $workspaceId, int $studentUserId): array
    {
        if ($userIds === []) {
            return [];
        }

        $this->primeScopes($userIds, $workspaceId);

        $studentCourses = null;
        $allowed = [];

        foreach ($userIds as $userId) {
            $scoped = $this->scopes[$userId.':'.$workspaceId] ?? null;

            if ($scoped === null) {
                $allowed[] = $userId;

                continue;
            }

            // Read once, and only when somebody on the list is confined.
            if ($studentCourses === null) {
                $student = User::query()->find($studentUserId);
                $studentCourses = $student instanceof User ? $this->enrollments->activeCourseIdsFor($student) : [];
            }

            if (array_intersect($scoped, $studentCourses) !== []) {
                $allowed[] = $userId;
            }
        }

        return $allowed;
    }

    /**
     * Fill both memos for many people in two reads — the live assignments of
     * the ones not yet known, then the scope rows of those assignments.
     *
     * @param  list<int>  $userIds
     */
    private function primeScopes(array $userIds, int $workspaceId): void
    {
        $unknown = array_values(array_filter(
            $userIds,
            fn (int $id): bool => ! array_key_exists($id.':'.$workspaceId, $this->scopes),
        ));

        if ($unknown === []) {
            return;
        }

        $assignments = AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('assistant_user_id', $unknown)
            ->active()
            ->pluck('id', 'assistant_user_id');

        $scopeRows = $assignments->isEmpty()
            ? collect()
            // ⚠️ Through the assignment ids just proved to be in this workspace —
            // `assistant_scopes` has no `workspace_id` of its own (see above).
            : AssistantScope::query()
                ->whereIn('assistant_assignment_id', $assignments->values()->all())
                ->get(['assistant_assignment_id', 'course_id'])
                ->groupBy('assistant_assignment_id');

        foreach ($unknown as $userId) {
            $key = $userId.':'.$workspaceId;
            $assignmentId = $assignments->get($userId);

            $this->assignments[$key] = $assignmentId === null ? null : (int) $assignmentId;

            if ($assignmentId === null) {
                $this->scopes[$key] = null;

                continue;
            }

            $courseIds = [];

            foreach ($scopeRows->get($assignmentId, collect()) as $row) {
                $courseIds[] = (int) $row->course_id;
            }

            // An empty scope is no confinement — the rule `scopedCourseIdsFor()` keeps.
            $this->scopes[$key] = $courseIds === [] ? null : $courseIds;
        }
    }

    /**
     * Drop the memoised scope of one person in one workspace.
     *
     * ⚠️ FOR A WRITER INSIDE THE SAME REQUEST, and only one exists:
     * `AddCreatedCourseToAssistantScope`. The memo lives for the whole request
     * (`scoped()`), so a scope row written after it was read would stay invisible
     * to every later policy check of the same request. Off the Shared contract on
     * purpose — no other module writes a scope.
     */
    public function forgetScopeOf(int $userId, int $workspaceId): void
    {
        unset($this->scopes[$userId.':'.$workspaceId]);
    }

    private function assignmentId(User $user, int $workspaceId): ?int
    {
        $key = $this->key($user, $workspaceId);

        if (array_key_exists($key, $this->assignments)) {
            return $this->assignments[$key];
        }

        $id = AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('assistant_user_id', $user->getKey())
            // Live only: a withdrawal takes effect on the next request, with no
            // session to end and nothing to sweep (SC-003).
            ->active()
            ->value('id');

        return $this->assignments[$key] = $id === null ? null : (int) $id;
    }

    private function key(User $user, int $workspaceId): string
    {
        return $user->getKey().':'.$workspaceId;
    }
}
