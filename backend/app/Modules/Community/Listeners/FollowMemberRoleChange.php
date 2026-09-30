<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Modules\Community\Support\AssistantAppointments;
use App\Modules\Tenancy\Events\WorkspaceMemberRoleChanged;

/**
 * A member's role moved, so their assistant assignment moves with it
 * (audit 2026-09-30).
 *
 * ⚠️ BOTH DIRECTIONS WERE WRONG BEFORE THIS. `UpdateWorkspaceMemberRole` moved
 * the pivot and spatie's role and nothing else: a teacher demoted to
 * `assistant-teacher` carried no assignment, so the financial wall
 * (`isAssistantIn()`) and the course confinement both passed them as if they
 * were still a teacher; an assistant promoted to teacher kept a live one, so the
 * wall went on refusing them the settlement that is now theirs.
 *
 * Synchronous, inside the role change's own transaction. A move between two
 * assistant roles leaves a live assignment — and its scope — untouched.
 */
class FollowMemberRoleChange
{
    public function __construct(private readonly AssistantAppointments $appointments) {}

    public function handle(WorkspaceMemberRoleChanged $event): void
    {
        $workspaceId = (int) $event->workspace->getKey();
        $userId = (int) $event->user->getKey();

        if (AssistantAppointments::isAssistantRole($event->toRole)) {
            $this->appointments->appoint(
                $workspaceId,
                $userId,
                $event->workspace->owner_user_id === null ? null : (int) $event->workspace->owner_user_id,
            );

            return;
        }

        $this->appointments->withdraw($workspaceId, $userId);
    }
}
