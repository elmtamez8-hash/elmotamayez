<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Support\StudentScope;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
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
        /*
        | ⛔ THE ASSISTANT SCOPE IS ASKED FIRST, ABOVE THE STUDENT BRANCH. That
        | branch is `StudentScope::permits()`, whose first arm allows ANY reader
        | whose context is the assignment's workspace — which a confined
        | assistant always has — so asked after it, a far course's published
        | homework opened to them through the student door. A no-op for everybody
        | who is not a confined assistant in this workspace, students included.
        */
        if (($scopeCheck = $this->withinAssistantScope($user, $assignment))->denied()) {
            return $scopeCheck;
        }

        // ⚠️ THIS ABILITY GUARDS A WRITE AS WELL AS A READ. `AssignmentController::submit()`
        // authorises `view`, and `SubmitAssignment` asks for no enrolment — so
        // "published ⇒ allow" put a stranger's uploaded file into a paying
        // teacher's marking queue. Same predicate as the list, one spelling.
        //
        // ⛔ ASKED ABOVE THE WORKSPACE CHECK, for the stamped student — see ExamPolicy::view().
        if ($assignment->isPublished() && StudentScope::permits($assignment, $user, app(EnrollmentDirectory::class))) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($assignment))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::ASSIGNMENTS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function manage(User $user, Assignment $assignment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($assignment))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $assignment))->denied()) {
            return $scopeCheck;
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

    /**
     * Whether this author may put homework in this course — on create, and on
     * an edit (a move is a create in the target course).
     *
     * ⚠️ `SaveAssignmentRequest::authorize()` asks the permission and nothing
     * else, so without this a confined assistant set homework for any course of
     * the workspace, or for none — and a course-less assignment reaches every
     * student of the workspace, the widest ground there is.
     */
    public function placeInCourse(User $user, int $workspaceId, ?int $courseId): Response
    {
        if (! $user->can(Permissions::ASSIGNMENTS_MANAGE)) {
            return Response::deny();
        }

        return $this->scopeAnswer($user, $workspaceId, $courseId);
    }

    /**
     * Spec 010 · FR-005 — a confined assistant manages the homework of their
     * courses. A course-less assignment is outside every confinement, and the
     * refusal for it lives in the directory (`mayActOnCourse()`'s null branch) —
     * the answer an exam set for no course gets on the grading board.
     *
     * A no-op for a teacher, an owner, a super admin and a student.
     */
    private function withinAssistantScope(User $user, Assignment $assignment): Response
    {
        return $this->scopeAnswer(
            $user,
            (int) $assignment->workspace_id,
            $assignment->course_id === null ? null : (int) $assignment->course_id,
        );
    }

    private function scopeAnswer(User $user, int $workspaceId, ?int $courseId): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $workspaceId, $courseId)
            ? Response::allow()
            : Response::deny('هذا الواجب خارج نطاق عملك.');
    }
}
