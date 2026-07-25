<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class AttemptPolicy extends BasePolicy
{
    public function view(User $user, Attempt $attempt): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($attempt))->denied()) {
            return $workspaceCheck;
        }

        if ($attempt->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        return $user->can(Permissions::ATTEMPTS_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    public function submit(User $user, Attempt $attempt): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($attempt))->denied()) {
            return $workspaceCheck;
        }

        return $attempt->student_user_id === $user->getKey()
            ? Response::allow()
            : Response::deny('You can only submit your own attempts.');
    }
}
