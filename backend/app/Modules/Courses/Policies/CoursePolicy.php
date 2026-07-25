<?php

declare(strict_types=1);

namespace App\Modules\Courses\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
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

        return $user->can(Permissions::LESSONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }
}
