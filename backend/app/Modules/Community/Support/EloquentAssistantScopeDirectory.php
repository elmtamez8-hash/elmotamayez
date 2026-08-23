<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
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

    public function mayActOnCourse(User $user, int $workspaceId, int $courseId): bool
    {
        $scoped = $this->scopedCourseIdsFor($user, $workspaceId);

        return $scoped === null || in_array($courseId, $scoped, true);
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
