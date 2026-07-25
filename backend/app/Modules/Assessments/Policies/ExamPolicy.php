<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Policies;

use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
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
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
        }

        if ($exam->isPublished()) {
            return Response::allow();
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

        return $user->can(Permissions::EXAMS_UPDATE)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, Exam $exam): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
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

        return $user->can(Permissions::EXAMS_PUBLISH)
            ? Response::allow()
            : Response::deny();
    }

    public function manageQuestions(User $user, Exam $exam): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($exam))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::QUESTIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }
}
