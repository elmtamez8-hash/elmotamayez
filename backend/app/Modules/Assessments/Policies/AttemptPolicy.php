<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

class AttemptPolicy extends BasePolicy
{
    /**
     * The staff list of handed-in papers (`GET /manage/attempts`).
     *
     * The permission alone: which rows a reader sees — their workspace, their
     * assistant confinement, the enrolment line — is the `ListStaffAttempts`
     * query's job, and it mirrors `view()` row for row: the confinement line
     * included, which `view()` did not carry until the grading board showed it.
     */
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::ATTEMPTS_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, Attempt $attempt): Response
    {
        // Ownership first: a student stamped with another teacher's workspace
        // fails the workspace check on their own paper.
        if ($attempt->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($attempt))->denied()) {
            return $workspaceCheck;
        }

        /*
        | ⛔ A CONFINED ASSISTANT OPENS THE PAPERS OF THEIR OWN COURSES ONLY.
        | This policy is the whole guard of two staff doors — the grading board's
        | `GET /manage/grading/attempts/{attempt}` and `GET /attempts/{uuid}` —
        | and without this line an assistant confined to one course read every
        | essay in the workspace by uuid while `GradingPolicy` refused them the
        | mark. Below the ownership branch, so a student's own paper is untouched;
        | an exam set for no course is refused to a confined assistant by the
        | directory's null branch.
        */
        if (($scopeCheck = $this->withinAssistantScope($user, $attempt))->denied()) {
            return $scopeCheck;
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

    /** Spec 010 · FR-005 — the same question `GradingPolicy` asks of an answer. */
    private function withinAssistantScope(User $user, Attempt $attempt): Response
    {
        $courseId = $attempt->exam?->course_id;

        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            (int) $attempt->workspace_id,
            $courseId === null ? null : (int) $courseId,
        )
            ? Response::allow()
            : Response::deny('هذه الورقة خارج نطاق عملك.');
    }

    /** Does this workspace still teach that student — `completed` keeps full access, so it counts. */
    private function teaches(Attempt $attempt): bool
    {
        return Enrollment::query()
            ->where('workspace_id', $attempt->workspace_id)
            ->where('student_user_id', $attempt->student_user_id)
            ->whereIn('status', Enrollment::GRANTING_STATUSES)
            ->exists();
    }

    public function submit(User $user, Attempt $attempt): Response
    {
        // Only the owner may submit, so the workspace check could only ever
        // refuse the owner — the stamped student on their own paper.
        return $attempt->student_user_id === $user->getKey()
            ? Response::allow()
            : Response::deny('You can only submit your own attempts.');
    }
}
