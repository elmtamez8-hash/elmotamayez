<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Whose work this is (FR-049).
 *
 * ⚠️ THIS POLICY IS RUN AGAIN WHEN THE FILE IS OPENED, not only when the link is
 * minted. A signed url outlives the moment it was issued by design; without the
 * re-check, a reader whose permission is withdrawn keeps reading until the
 * signature expires — which is the half of FR-048أ that a signature alone cannot
 * express, because a signature proves who asked, never whether they may still.
 */
class SubmissionPolicy extends BasePolicy
{
    public function view(User $user, Submission $submission): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($submission))->denied()) {
            return $workspaceCheck;
        }

        if ($submission->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        return $user->can(Permissions::SUBMISSIONS_GRADE)
            ? Response::allow()
            : Response::deny();
    }

    public function grade(User $user, Submission $submission): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($submission))->denied()) {
            return $workspaceCheck;
        }

        // Nobody marks their own work, whatever they hold — the same rule the
        // essay board applies, and for the same reason.
        if ($submission->student_user_id === $user->getKey()) {
            return Response::deny('You cannot grade your own submission.');
        }

        return $user->can(Permissions::SUBMISSIONS_GRADE)
            ? Response::allow()
            : Response::deny();
    }
}
