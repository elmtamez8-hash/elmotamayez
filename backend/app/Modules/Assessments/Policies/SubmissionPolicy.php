<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Learning\Models\Enrollment;
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

        if (! $user->can(Permissions::SUBMISSIONS_GRADE)) {
            return Response::deny();
        }

        /*
        | ⚠️ THE PERMISSION IS NOT THE WHOLE GUARD — THE SAME OMISSION
        | `AttemptPolicy` WAS FIXED FOR, IN THE SAME MODULE. `submissions.grade`
        | alone lets an assistant open the coursework of somebody whose enrolment
        | lapsed a year ago; NFR-001أ draws the line at an enrolment, not at a row
        | that happens to carry the same workspace id. And this policy is not only
        | read here: `SubmissionFileController` re-runs it at every file open, so
        | the gap was a permanent read on a former student's uploaded work.
        |
        | The second branch keeps a paper handed in during a term readable after
        | that term ends, or work submitted on the last day would be unmarkable
        | for ever — the same pairing, and the same reason, as the attempt guard.
        */
        return $this->belongsToATaughtStudent($submission) ? Response::allow() : Response::deny();
    }

    /** An active enrolment now, or an enrolment behind the work itself. */
    private function belongsToATaughtStudent(Submission $submission): bool
    {
        $active = Enrollment::query()
            ->where('workspace_id', $submission->workspace_id)
            ->where('student_user_id', $submission->student_user_id)
            ->where('status', 'active')
            ->exists();

        if ($active) {
            return true;
        }

        $courseId = $submission->assignment?->course_id;

        if ($courseId === null) {
            return false;
        }

        return Enrollment::query()
            ->where('workspace_id', $submission->workspace_id)
            ->where('student_user_id', $submission->student_user_id)
            ->where('course_id', $courseId)
            ->exists();
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
