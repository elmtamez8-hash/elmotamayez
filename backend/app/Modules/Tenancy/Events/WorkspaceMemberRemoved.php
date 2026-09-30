<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member was taken out of a workspace by `RemoveMember`.
 *
 * ⚠️ DISPATCHED INSIDE THE REMOVAL'S TRANSACTION, LISTENERS SYNCHRONOUS — the
 * assistant assignment (Community) is revoked in the same commit, or a person
 * who is no longer on the team keeps a live assignment that a later invitation
 * would silently revive with its old confinement.
 */
class WorkspaceMemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $user,
    ) {}
}
