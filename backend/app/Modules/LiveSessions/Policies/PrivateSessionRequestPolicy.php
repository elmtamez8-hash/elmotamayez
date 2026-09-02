<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * The teacher's queue, and the student's own row in it.
 *
 * ⚠️ TWO READERS, TWO DIFFERENT KINDS OF ABILITY, AND ONLY ONE OF THEM IS A
 * PERMISSION. `decide` is the teacher's; `view` and `withdraw` are OWNERSHIP
 * tests, because a student holds no workspace role at all — they are a member of
 * no workspace, so the spatie team id is null and every `can()` below it is
 * false. A permission check on the student's own request denies the person the
 * row belongs to.
 *
 * ⚠️ AND `belongsToCurrentWorkspace()` RAISES NO OBJECTION WHEN THE CONTEXT IS
 * NULL, which is what it always is for a student. That is why the ownership
 * branch is asked FIRST and answers on its own: reaching the workspace check at
 * all for a student would be relying on an answer that is only accidentally
 * permissive.
 */
class PrivateSessionRequestPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::SESSIONS_MANAGE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة طلبات الحصص الخاصة.');
    }

    public function view(User $user, PrivateSessionRequest $request): Response
    {
        if ($this->owns($user, $request)) {
            return Response::allow();
        }

        return $this->decide($user, $request);
    }

    public function decide(User $user, PrivateSessionRequest $request): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($request))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SESSIONS_MANAGE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة طلبات الحصص الخاصة.');
    }

    public function withdraw(User $user, PrivateSessionRequest $request): Response
    {
        return $this->owns($user, $request)
            ? Response::allow()
            : Response::deny('هذا الطلب ليس لك.');
    }

    private function owns(User $user, PrivateSessionRequest $request): bool
    {
        return (int) $request->student_user_id === (int) $user->getKey();
    }
}
