<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Learning\Models\Enrollment;
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

        if (! $user->can(Permissions::ATTEMPTS_VIEW_ALL)) {
            return Response::deny();
        }

        /*
        | ⚠️ THE PERMISSION IS NOT THE WHOLE GUARD, and the grading queue is what
        | made that visible. `ATTEMPTS_VIEW_ALL` alone lets an assistant open the
        | full text of an essay written by somebody whose enrolment lapsed a year
        | ago — a person the workspace no longer teaches, whose paper is still in
        | its tables. NFR-001أ draws the line at an enrolment, not at a row.
        |
        | The second branch is not redundant: an attempt made INSIDE an enrolment
        | here stays readable after that enrolment ends, or a paper handed in on
        | the last day of a term hangs in the queue for ever with nobody entitled
        | to mark it. What neither branch reaches is an attempt with no enrolment
        | behind it at all — a paper the student generated for themselves.
        */
        if ($attempt->enrollment_id !== null) {
            return Response::allow();
        }

        return $this->teaches($attempt) ? Response::allow() : Response::deny();
    }

    /** Does this workspace currently have an active enrolment for that student? */
    private function teaches(Attempt $attempt): bool
    {
        return Enrollment::query()
            ->where('workspace_id', $attempt->workspace_id)
            ->where('student_user_id', $attempt->student_user_id)
            ->where('status', 'active')
            ->exists();
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
