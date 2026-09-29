<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Support\Roles;

/**
 * How many teachers a workspace has — the one fact that decides whether the team
 * screen names each course's teacher (an academy) or leaves it implied (one
 * teacher, who is the reader).
 *
 * ⚠️ THE PIVOT ROLE, ASKED IN THE POSITIVE, NEVER MERE MEMBERSHIP.
 * `workspace_members` carries student and assistant rows too, so «members» is not
 * «teachers»; and this predicate only decides whether a label is SHOWN, so an
 * unknown role falling toward «not a teacher» costs nothing.
 */
final class WorkspaceTeachers
{
    public static function count(int $workspaceId): int
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('role', [Roles::TENANT_OWNER, Roles::TEACHER])
            ->distinct()
            ->count('user_id');
    }
}
