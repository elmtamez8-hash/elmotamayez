<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Tenancy\Support\StaffAccounts;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardOwnership;
use App\Modules\Whiteboard\Support\MemberRoles;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;

/**
 * Who may see, edit, take over and delete a whiteboard (spec 039, contracts/api.md).
 *
 * ⚠️ NOT `BasePolicy::belongsToCurrentWorkspace()`. That helper ALLOWS when the
 * context resolves to null — and a member removed from the workspace has exactly
 * that (`RemoveMember` clears `last_workspace_id`), so they would keep their old
 * boards. Every method here demands a resolved context that MATCHES the board.
 *
 * ⚠️ AND A LEARNER IS REFUSED IN THE POSITIVE, never by the absence of a
 * permission (gotchas/tenancy.md: «a permission classified by absence guards
 * nothing»). A guardian is not a workspace role at all — it is
 * `users.platform_role` — so the refusal asks `StaffAccounts::isLearnerAccount()`
 * beside the membership row.
 *
 * The roles (owner decisions Q5, D1):
 *  - the owning teacher (`BoardOwnership`) edits, takes over the lock, deletes;
 *  - the workspace manager who is NOT that teacher sees, exports, deletes — and
 *    never edits;
 *  - the course's other authors (assistants within scope, co-teachers) edit a
 *    board that hangs off a live course.
 */
class BoardPolicy
{
    public function viewAny(User $user): Response
    {
        $workspaceId = $this->context();

        return $workspaceId !== null
            && $this->isStaff($user, $workspaceId)
            && $user->can(Permissions::LESSONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * The course rule for a confined assistant (course-less refused) is the
     * Action's, because only the Action knows which course was asked for.
     */
    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Board $board): Response
    {
        if (! $this->inContext($board) || $this->isHidden($board)) {
            return Response::denyAsNotFound();
        }

        if (! $this->isStaff($user, $board->workspace_id)) {
            return Response::deny();
        }

        return $this->isOwningTeacher($user, $board)
            || $this->isManager($user, $board->workspace_id)
            || $this->update($user, $board)->allowed()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Board $board): Response
    {
        if (! $this->inContext($board) || $this->isHidden($board)) {
            return Response::denyAsNotFound();
        }

        if (! $this->isStaff($user, $board->workspace_id) || ! $user->can(Permissions::LESSONS_MANAGE)) {
            return Response::deny();
        }

        if ($this->isOwningTeacher($user, $board)) {
            return Response::allow();
        }

        // Q5: the manager sees another teacher's board and never edits it.
        if ($this->isManager($user, $board->workspace_id)) {
            return Response::deny('مدير الأكاديمية يرى سبّورات المدرّسين ولا يعدّل فيها.');
        }

        // The course's other authors, and only while the course is live: a board
        // whose course was deleted belongs to its owning teacher alone.
        //
        // ⚠️ A REFUSAL HERE IS A 404, NOT A 403. Whoever fails this branch is neither
        // the owning teacher nor the manager, so they cannot VIEW the board either —
        // and a 403 on the write door beside a 404 on the read door would tell them
        // the board exists (caught by BoardIsolationTest).
        $course = $board->course_id === null ? null : $board->course;
        if (! $course instanceof Course || $course->trashed()) {
            return Response::denyAsNotFound();
        }

        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $board->workspace_id, (int) $course->getKey())
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** «خُذ التحرير» — the owning teacher alone (Q3, D1). */
    public function takeLock(User $user, Board $board): Response
    {
        if (! $this->inContext($board) || $this->isHidden($board)) {
            return Response::denyAsNotFound();
        }

        if ($this->isStaff($user, $board->workspace_id)
            && $user->can(Permissions::LESSONS_MANAGE)
            && $this->isOwningTeacher($user, $board)) {
            return Response::allow();
        }

        return $this->refusal($user, $board);
    }

    public function export(User $user, Board $board): Response
    {
        return $this->view($user, $board);
    }

    public function delete(User $user, Board $board): Response
    {
        if (! $this->inContext($board) || $this->isHidden($board)) {
            return Response::denyAsNotFound();
        }

        if ($this->isStaff($user, $board->workspace_id)
            && ($this->isOwningTeacher($user, $board) || $this->isManager($user, $board->workspace_id))) {
            return Response::allow();
        }

        return $this->refusal($user, $board);
    }

    /**
     * A 403 only to someone who can SEE the board; to anyone else a 404, exactly as
     * on the read door — a 403 beside a 404 tells a stranger the board exists.
     */
    private function refusal(User $user, Board $board): Response
    {
        return $this->view($user, $board)->allowed() ? Response::deny() : Response::denyAsNotFound();
    }

    private function context(): ?int
    {
        return app(WorkspaceContext::class)->id();
    }

    private function inContext(Board $board): bool
    {
        $workspaceId = $this->context();

        return $workspaceId !== null && $workspaceId === (int) $board->workspace_id;
    }

    /** A board being built or deleted by a queued job is invisible until the job ends. */
    private function isHidden(Board $board): bool
    {
        return in_array($board->pending_operation, [BoardPendingOperation::Building, BoardPendingOperation::Deleting], true);
    }

    /** A membership row with a staff role, on an account that is not a student's or a guardian's. */
    private function isStaff(User $user, int $workspaceId): bool
    {
        if (StaffAccounts::isLearnerAccount($user)) {
            return false;
        }

        $role = $this->roleIn($user, $workspaceId);

        return $role !== null && StaffAccounts::isStaffRole($role);
    }

    private function isManager(User $user, int $workspaceId): bool
    {
        return $this->roleIn($user, $workspaceId) === Roles::TENANT_OWNER
            && ! app(AssistantScopeDirectory::class)->isAssistantIn($user, $workspaceId);
    }

    private function isOwningTeacher(User $user, Board $board): bool
    {
        return BoardOwnership::owningTeacherId($board) === (int) $user->getKey();
    }

    private function roleIn(User $user, int $workspaceId): ?string
    {
        return app(MemberRoles::class)->roleIn($user, $workspaceId);
    }
}
