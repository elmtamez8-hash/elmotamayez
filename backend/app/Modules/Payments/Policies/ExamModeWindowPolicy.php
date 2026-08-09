<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The one billing decision that IS the teacher's.
 *
 * Opening a window forces the floor to zero over their own workspace for a
 * stretch of days — it withholds nothing and mints nothing, it only stops the
 * credit limit from deferring anything during exams. That is a teaching
 * judgement about their own students, which is why it sits in the teacher role
 * while every other billing write sits with the platform.
 */
class ExamModeWindowPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, ExamModeWindow $window): Response
    {
        return $this->belongsToCurrentWorkspace($window);
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::BILLING_EXAM_MODE_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, ExamModeWindow $window): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($window))->denied()) {
            return $workspaceCheck;
        }

        return $this->create($user);
    }

    public function delete(User $user, ExamModeWindow $window): Response
    {
        return $this->update($user, $window);
    }
}
