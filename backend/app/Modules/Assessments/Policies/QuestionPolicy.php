<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Question;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may read the bank, and who may write to it.
 *
 * ⚠️ THE TWO PERMISSIONS ARE NOT INTERCHANGEABLE. `BANK_VIEW` is browsing and
 * searching — what an assistant building a lesson needs. `QUESTIONS_MANAGE` is
 * authorship over a library shared by every exam in the workspace, which is why
 * spec 008 took it off the assistant role and left the read half behind. Reading
 * one gate for both abilities silently restores what that split removed.
 */
class QuestionPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::BANK_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, Question $question): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($question))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::BANK_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::QUESTIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Question $question): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($question))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::QUESTIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Taking a question out of circulation.
     *
     * FR-005: a question with recorded attempts is disabled, never deleted, and
     * the ability is named `disable` rather than `delete` so that no caller can
     * reach a hard delete by asking for the ability Laravel's resource
     * conventions hand out by default. `delete` has no method here, and a policy
     * with no matching method denies.
     */
    public function disable(User $user, Question $question): Response
    {
        return $this->update($user, $question);
    }

    /**
     * Putting a bank question into an exam.
     *
     * The exam side is checked separately (`ExamPolicy::manageQuestions`): one
     * question and one exam are two rows that can belong to two workspaces, and
     * a single check would authorise whichever of them the caller happens to own.
     */
    public function include(User $user, Question $question): Response
    {
        return $this->view($user, $question);
    }
}
