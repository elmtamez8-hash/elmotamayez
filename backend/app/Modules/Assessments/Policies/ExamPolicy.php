<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Support\StudentScope;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Auth\Access\Response;

class ExamPolicy extends BasePolicy
{
    /**
     * Any member may list exams. What they get back is narrowed by the query:
     * published exams for everyone, drafts only with EXAMS_VIEW — the same split
     * {@see view()} applies to a single exam. Requiring EXAMS_VIEW here would let
     * a student open an exam by uuid but never find it in a list.
     */
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, Exam $exam): Response
    {
        /*
        | ⛔ THE ASSISTANT SCOPE IS ASKED FIRST, ABOVE THE STUDENT BRANCH — the
        | `AssignmentPolicy::view()` rule, for the same reason. The branch below is
        | `StudentScope::permits()`, whose first arm allows ANY reader whose
        | context is the exam's workspace — which a confined assistant always has
        | — so asked after it, a far course's published paper opened to them, and
        | `AttemptController::start()` (which authorises `view`) let them sit it.
        | A no-op for everybody who is not a confined assistant in this workspace.
        |
        | ⚠️ A REFUSAL FALLS THROUGH TO THE STUDENT'S OWN ENTITLEMENT, NEVER TO
        | NOTHING: a confined assistant actively enrolled in the far course opens
        | and sits its PUBLISHED exam as a student. Only the ENROLMENT arm is
        | asked, never `StudentScope::permits()`'s «context matches» arm (the leak
        | this closes), and it grants the student powers only — every staff
        | ability below stays refused. A course-less exam has no course to be
        | enrolled in and stays refused.
        */
        if (($scopeCheck = $this->withinAssistantScope($user, $exam))->denied()) {
            return $exam->isPublished() && $this->enrolledInItsCourse($user, $exam)
                ? Response::allow()
                : $scopeCheck;
        }

        // ⚠️ PUBLISHED IS NOT AN ENTITLEMENT — it is a fact about the paper, and
        // the caller has to be asked about separately. {@see StudentScope::permits()}
        // is the spelling `ExamController::index()` already uses on the list.
        //
        // ⛔ AND IT IS ASKED ABOVE THE WORKSPACE CHECK. A student stamped with
        // another teacher's workspace (`users.last_workspace_id`) fails that check
        // for the paper they enrolled for — the allow below it was unreachable
        // for the one person it exists for. The check still guards the teacher arm.
        if ($exam->isPublished() && StudentScope::permits($exam, $user, app(EnrollmentDirectory::class))) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::EXAMS_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::EXAMS_CREATE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Exam $exam): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $exam))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::EXAMS_UPDATE)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, Exam $exam): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $exam))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::EXAMS_DELETE)
            ? Response::allow()
            : Response::deny();
    }

    public function publish(User $user, Exam $exam): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $exam))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::EXAMS_PUBLISH)
            ? Response::allow()
            : Response::deny();
    }

    public function manageQuestions(User $user, Exam $exam): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $exam))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::QUESTIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Whether this author may put an exam in this course — on create, and on an
     * edit (a move is a create in the target course).
     *
     * ⚠️ IT ANSWERS «WHERE» ONLY, AND IS ASKED BESIDE THE PERMISSION, NEVER
     * INSTEAD OF IT: `StoreExamRequest` asks `exams.create`, and `update()` above
     * asks `exams.update`. Before it, both asked the permission and nothing
     * else, so a confined assistant set an exam for any course of the workspace,
     * or for none — and a course-less exam reaches every student of the
     * workspace, the widest ground there is.
     */
    public function placeInCourse(User $user, int $workspaceId, ?int $courseId): Response
    {
        return $this->scopeAnswer($user, $workspaceId, $courseId);
    }

    /**
     * Spec 010 · FR-005 — a confined assistant manages the exams of their
     * courses. A course-less exam is outside every confinement, and the refusal
     * for it lives in the directory (`mayActOnCourse()`'s null branch) — the
     * answer `AttemptPolicy` and `GradingPolicy` already give the papers sat
     * against it.
     *
     * A no-op for a teacher, an owner, a super admin and a student.
     */
    private function withinAssistantScope(User $user, Exam $exam): Response
    {
        return $this->scopeAnswer(
            $user,
            (int) $exam->workspace_id,
            $exam->course_id === null ? null : (int) $exam->course_id,
        );
    }

    /**
     * An active enrolment in the exam's own course. A course-less exam has none
     * to be enrolled in, so it stays refused.
     */
    private function enrolledInItsCourse(User $user, Exam $exam): bool
    {
        if ($exam->course_id === null) {
            return false;
        }

        return in_array(
            (int) $exam->course_id,
            array_map('intval', app(EnrollmentDirectory::class)->activeCourseIdsFor($user)),
            true,
        );
    }

    private function scopeAnswer(User $user, int $workspaceId, ?int $courseId): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $workspaceId, $courseId)
            ? Response::allow()
            : Response::deny('هذا الاختبار خارج نطاق عملك.');
    }
}
