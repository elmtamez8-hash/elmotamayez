<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Modules\Community\Support\AssistantAppointments;
use App\Modules\Tenancy\Events\WorkspaceMemberRemoved;

/**
 * A member was taken out of the workspace, so their assistant assignment closes
 * (audit 2026-09-30).
 *
 * ⚠️ `RemoveMember` DETACHED THE MEMBERSHIP AND LEFT THE ASSIGNMENT LIVE. The
 * person could not get in — the membership is gone — but the team screen still
 * listed them as a current assistant, and the row stayed «live» for anything
 * that asks the assignment alone. Synchronous, inside the removal's transaction.
 */
class RevokeAssignmentOfRemovedMember
{
    public function __construct(private readonly AssistantAppointments $appointments) {}

    public function handle(WorkspaceMemberRemoved $event): void
    {
        $this->appointments->withdraw((int) $event->workspace->getKey(), (int) $event->user->getKey());
    }
}
