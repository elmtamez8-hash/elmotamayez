<?php

declare(strict_types=1);

namespace App\Modules\Courses\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

class CoursePolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::COURSES_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        if ($course->isPublished()) {
            return Response::allow();
        }

        return $user->can(Permissions::COURSES_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::COURSES_CREATE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $course))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny();
    }

    public function delete(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::COURSES_DELETE)
            ? Response::allow()
            : Response::deny();
    }

    public function publish(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::COURSES_PUBLISH)
            ? Response::allow()
            : Response::deny();
    }

    public function manageLessons(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $course))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::LESSONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Spec 010 · FR-005 — an assistant confined to a set of courses works on those.
     *
     * ⚠️ A SECOND QUESTION, ASKED BESIDE THE PERMISSION AND NEVER INSTEAD OF IT.
     * The permission answers «may this role ever edit content»; this answers «on
     * this course». It returns allow for everybody who is not an assistant here —
     * a teacher, an owner, a super admin — because none of them is confined by an
     * assignment, so the whole of it is a no-op on every workspace with no team.
     */
    private function withinAssistantScope(User $user, Course $course): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            (int) $course->workspace_id,
            (int) $course->getKey(),
        )
            ? Response::allow()
            : Response::deny('هذا الكورس خارج نطاق عملك.');
    }
}
