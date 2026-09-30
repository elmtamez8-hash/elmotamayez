<?php

declare(strict_types=1);

namespace App\Modules\Learning\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

/**
 * The queue, and the student's own row in it.
 *
 * ⚠️ TWO DIFFERENT READERS, TWO DIFFERENT ABILITIES. `decide` is the teacher's
 * (`COURSES_UPDATE`); `withdraw` is the student's, and it is an OWNERSHIP test
 * rather than a permission — a student holds no workspace role at all, so a
 * permission check there denies the person the row belongs to.
 *
 * ⚠️ AND THE TEACHER'S SIDE ASKS THE ASSISTANT SCOPE (spec 010 · FR-005, audit
 * 2026-09-30): the queue is one course's queue, and a confined assistant reads
 * and decides it on the courses they work on only — see `CohortPolicy`.
 */
class CohortTransferRequestPolicy extends BasePolicy
{
    /** Asked as `authorize('viewAny', [CohortTransferRequest::class, $course])`. */
    public function viewAny(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, (int) $course->workspace_id, (int) $course->getKey()))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة طلبات الانتقال.');
    }

    public function decide(User $user, CohortTransferRequest $request): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($request))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, (int) $request->workspace_id, (int) $request->course_id))->denied()) {
            return $scopeCheck;
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

    private function withinAssistantScope(User $user, int $workspaceId, int $courseId): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $workspaceId, $courseId)
            ? Response::allow()
            : Response::deny('هذا الكورس خارج نطاق عملك.');
    }
}
