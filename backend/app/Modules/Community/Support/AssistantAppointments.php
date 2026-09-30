<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AssistantScopeDirectory;

/**
 * The one place an assistant assignment is opened, re-opened or closed because
 * of what happened to a MEMBERSHIP — joining, a role change, a removal.
 *
 * ⚠️ THREE LISTENERS, ONE RULE. `CreateAssistantAssignment` (joined),
 * `FollowMemberRoleChange` (role moved) and `RevokeAssignmentOfRemovedMember`
 * (taken out) all answer «is this role somebody's assistant» with
 * {@see self::isAssistantRole()}. Until 2026-09-30 only the first existed, so a
 * teacher demoted to an assistant carried no assignment — no financial wall, no
 * confinement — and an assistant promoted to teacher kept one, walled out of
 * their own settlement; a removed member's assignment stayed live.
 *
 * ⚠️ NOT `RevokeAssistant`. That Action is the teacher's «withdraw this
 * assistant», and it also deletes the MEMBERSHIP — called on a role change it
 * would throw out of the workspace the very person whose role was just set.
 * Closing here is the bare conditional UPDATE and nothing else.
 */
final class AssistantAppointments
{
    /**
     * Roles that are not somebody's assistant. EVERYBODY ELSE IS — including the
     * custom role an owner invents, which is exactly the person FR-003 is about.
     * The list fails safe: a new role is walled until somebody decides otherwise.
     */
    public const NOT_ASSISTANTS = [Roles::TENANT_OWNER, Roles::TEACHER, Roles::STUDENT];

    public function __construct(private readonly AssistantScopeDirectory $directory) {}

    public static function isAssistantRole(string $role): bool
    {
        return ! in_array($role, self::NOT_ASSISTANTS, true);
    }

    /**
     * Open an assignment, or re-open a revoked one.
     *
     * ⚠️ A RE-OPENED ASSIGNMENT COMES BACK CONFINED TO ITS OLD SCOPE (owner
     * decision 2026-09-30). The `assistant_scopes` rows are KEPT: an assistant
     * the teacher confined, removed and invited back must not return with every
     * course open to them. Clearing the rows would do exactly that, because no
     * rows means «every course».
     *
     * ⚠️ AND A SCOPE WHOSE COURSES WERE ALL DELETED STAYS «CONFINED TO NOTHING».
     * `assistant_scopes.course_id` has no foreign key and nothing but
     * `SetAssistantScope` deletes a row, so a deleted course's row survives.
     * `scopedCourseIdsFor()` therefore still returns a non-empty list, and
     * `mayActOnCourse()` refuses every live course. The team screen counts such
     * rows as `unavailable_courses_count`. Never «tidy» those rows here: an
     * empty scope reads as UNCONFINED. A LIVE assignment is left exactly as it
     * is (a move between two assistant roles changes neither its wall nor its
     * scope).
     */
    public function appoint(int $workspaceId, int $userId, ?int $invitedBy): void
    {
        $existing = AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('assistant_user_id', $userId)
            ->first();

        if ($existing instanceof AssistantAssignment) {
            if ($existing->revoked_at !== null) {
                // `revoked_at` is not fillable — it is claimed by a conditional UPDATE.
                $existing->forceFill(['revoked_at' => null])->save();
            }
        } else {
            AssistantAssignment::query()->create([
                'workspace_id' => $workspaceId,
                'assistant_user_id' => $userId,
                'invited_by_user_id' => $invitedBy,
            ]);
        }

        $this->forget($userId, $workspaceId);
    }

    /** Close the live assignment, if there is one. Idempotent. */
    public function withdraw(int $workspaceId, int $userId): void
    {
        AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('assistant_user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->forget($userId, $workspaceId);
    }

    /**
     * ⚠️ THE DIRECTORY MEMOISES PER REQUEST (and a test container is not flushed
     * between calls), so without this a role changed earlier in the same request
     * would still be walled — or still not — by the answer read before it.
     */
    private function forget(int $userId, int $workspaceId): void
    {
        if ($this->directory instanceof EloquentAssistantScopeDirectory) {
            $this->directory->forgetAssignmentOf($userId, $workspaceId);
        }
    }
}
