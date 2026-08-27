<?php

declare(strict_types=1);

namespace App\Modules\Learning\Policies;

use App\Models\User;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The queue, and the student's own row in it.
 *
 * ⚠️ TWO DIFFERENT READERS, TWO DIFFERENT ABILITIES. `decide` is the teacher's
 * (`COURSES_UPDATE`); `withdraw` is the student's, and it is an OWNERSHIP test
 * rather than a permission — a student holds no workspace role at all, so a
 * permission check there denies the person the row belongs to.
 */
class CohortTransferRequestPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة طلبات الانتقال.');
    }

    public function decide(User $user, CohortTransferRequest $request): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($request))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة طلبات الانتقال.');
    }

    public function withdraw(User $user, CohortTransferRequest $request): Response
    {
        return (int) $request->student_user_id === (int) $user->getKey()
            ? Response::allow()
            : Response::deny('هذا الطلب ليس لك.');
    }
}
