<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;

class FreezePeriodPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::FREEZE_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, FreezePeriod $period): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($period))->denied()) {
            return $workspaceCheck;
        }

        // FR-044: readable with its reason and its author, so a suspended session
        // can be explained to the student who booked it.
        return $user->can(Permissions::FREEZE_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        if (! $user->can(Permissions::FREEZE_MANAGE)) {
            return Response::deny();
        }

        $workspaceId = app(WorkspaceContext::class)->id();

        // No context: a super admin operating globally, whom no assignment confines.
        return $workspaceId === null
            ? Response::allow()
            : $this->notConfined($user, $workspaceId);
    }

    public function delete(User $user, FreezePeriod $period): Response
    {
        if (($readCheck = $this->view($user, $period))->denied()) {
            return $readCheck;
        }

        return $this->notConfined($user, (int) $period->workspace_id);
    }

    /**
     * ⛔ A CONFINED ASSISTANT NEITHER DECLARES NOR LIFTS A FREEZE (spec 010 ·
     * FR-005). A freeze has no course: a workspace-wide one suspends every
     * session of every course, and a one-student freeze releases that student's
     * seats in every course they study — so either kind reaches outside any
     * confinement. It is the course-less answer `mayActOnCourse(…, null)` gives
     * everywhere else. Reading (`viewAny`/`view`) stays: explaining why a
     * session of their own course is suspended is the assistant's job too.
     */
    private function notConfined(User $user, int $workspaceId): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $workspaceId, null)
            ? Response::allow()
            : Response::deny('التجميد يشمل كل الكورسات، وهو خارج نطاق عملك.');
    }
}
