<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Tenancy\Models\Workspace;
use App\Policies\BasePolicy;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;

/**
 * Who may build and cut a teacher's team.
 *
 * ⚠️ THE WORKSPACE OWNER ALONE, READ FROM `workspaces.owner_user_id` — never
 * "a member holding a broad permission". Widening the scope of an assistant is
 * widening what they may reach, so an assistant who could edit an assignment
 * could raise their own ceiling, and one who could edit a colleague's could
 * reach through them. The column is the one thing that says whose workspace this
 * is.
 *
 * ⚠️ AND `viewAny()` IS NOT `Response::allow()`. A policy method no HTTP route
 * exercises is a method nobody has ever tested, and its default is whatever the
 * first person wrote — which is precisely how `OrderPolicy::viewAny()` shipped
 * open and handed the panel's order list to every assistant (`981ca23`). If a
 * Filament resource is ever built over this model, this is the only gate its
 * table will consult.
 */
class AssistantAssignmentPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->ownsCurrentWorkspace($user);
    }

    public function view(User $user, AssistantAssignment $assignment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($assignment))->denied()) {
            return $workspaceCheck;
        }

        return $this->ownsCurrentWorkspace($user);
    }

    public function update(User $user, AssistantAssignment $assignment): Response
    {
        return $this->view($user, $assignment);
    }

    /**
     * Removing an assistant.
     *
     * Named `delete` because that is the ability Laravel's helpers reach for, and
     * it deletes nothing: `RevokeAssistant` stamps `revoked_at` so the grading and
     * the attendance marks the assistant made stay attributed to them (FR-009).
     */
    public function delete(User $user, AssistantAssignment $assignment): Response
    {
        return $this->view($user, $assignment);
    }

    private function ownsCurrentWorkspace(User $user): Response
    {
        $workspace = app(WorkspaceContext::class)->current();

        return $workspace instanceof Workspace && $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny();
    }
}
