<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may put a mark on somebody else's paper.
 *
 * ⚠️ TWO PERMISSIONS, NOT ONE. FR-031 hands an assistant the queue; FR-032
 * changes a mark that has already been given, and told to the student. The
 * second is the teacher's own decision to overrule the first, so an assistant
 * granted `grading.perform` does not thereby acquire the power to quietly
 * rewrite their own earlier marks — or their colleague's.
 */
class GradingPolicy extends BasePolicy
{
    public function perform(User $user, Answer $answer): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($answer))->denied()) {
            return $workspaceCheck;
        }

        // Nobody marks their own paper, whatever they hold. The queue is built
        // from other people's work by definition, and the one case this catches
        // is a teacher enrolled as a student in their own workspace.
        if ($answer->student_user_id === $user->getKey()) {
            return Response::deny('You cannot grade your own answer.');
        }

        return $user->can(Permissions::GRADING_PERFORM)
            ? Response::allow()
            : Response::deny();
    }

    public function revise(User $user, Answer $answer): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($answer))->denied()) {
            return $workspaceCheck;
        }

        if ($answer->student_user_id === $user->getKey()) {
            return Response::deny('You cannot grade your own answer.');
        }

        return $user->can(Permissions::GRADING_REVISE)
            ? Response::allow()
            : Response::deny();
    }
}
