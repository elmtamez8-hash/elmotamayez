<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member of a workspace moved from one role to another.
 *
 * ⚠️ DISPATCHED INSIDE `UpdateWorkspaceMemberRole`'s TRANSACTION, AND ITS
 * LISTENERS ARE SYNCHRONOUS. What hangs off a role outside this module — the
 * assistant assignment the financial wall and the course confinement read
 * (Community) — must move in the same commit as the role itself: promoted to
 * teacher with the assignment still live, the wall keeps refusing them their own
 * settlement; demoted to an assistant with no assignment, they walk past it.
 */
class WorkspaceMemberRoleChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $user,
        public readonly string $fromRole,
        public readonly string $toRole,
    ) {}
}
