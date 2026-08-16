<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may write homework, and who may see it.
 *
 * ⚠️ A DRAFT IS NOT READABLE BY A STUDENT. `assignments.manage` reads anything;
 * everyone else reads published only. Without that branch a student sees the
 * homework their teacher is still drafting — including a deadline that has not
 * been decided, which they will then plan around.
 */
class AssignmentPolicy extends BasePolicy
{
    public function view(User $user, Assignment $assignment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($assignment))->denied()) {
            return $workspaceCheck;
        }

        if ($user->can(Permissions::ASSIGNMENTS_MANAGE)) {
            return Response::allow();
        }

        return $assignment->isPublished() ? Response::allow() : Response::deny();
    }

    public function manage(User $user, Assignment $assignment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($assignment))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::ASSIGNMENTS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Assignment $assignment): Response
    {
        return $this->manage($user, $assignment);
    }

    public function delete(User $user, Assignment $assignment): Response
    {
        return $this->manage($user, $assignment);
    }
}
