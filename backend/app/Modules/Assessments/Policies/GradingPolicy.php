<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
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

        if (($scopeCheck = $this->withinAssistantScope($user, $answer))->denied()) {
            return $scopeCheck;
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

        if (($scopeCheck = $this->withinAssistantScope($user, $answer))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::GRADING_REVISE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Spec 010 · FR-005 — a confined assistant marks the papers of their courses.
     *
     * ⚠️ THE COURSE COMES FROM THE EXAM, WHICH MAY NOT HAVE ONE. An exam set for
     * the workspace at large carries no `course_id`, and the null branch belongs
     * to the directory rather than here: a confined assistant is refused it there,
     * once, for all three surfaces that ask.
     *
     * A no-op for a teacher, an owner and a super admin, none of whom is confined
     * by an assignment.
     */
    private function withinAssistantScope(User $user, Answer $answer): Response
    {
        $courseId = $answer->attempt?->exam?->course_id;

        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            (int) $answer->workspace_id,
            $courseId === null ? null : (int) $courseId,
        )
            ? Response::allow()
            : Response::deny('هذه الورقة خارج نطاق عملك.');
    }
}
