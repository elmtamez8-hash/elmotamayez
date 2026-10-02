<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A member's pivot role in a workspace, read once per request.
 *
 * `BoardPolicy` asks it on every ability, and a list asks every ability for every
 * row — without the memo that is a query per row per ability. Bound `scoped` in the
 * provider: one instance per request or queued job, never shared across them (a
 * singleton would carry one request's answer into the next job on the worker).
 */
final class MemberRoles
{
    /** @var array<string, string|null> */
    private array $roles = [];

    public function roleIn(User $user, int $workspaceId): ?string
    {
        $key = $user->getKey().':'.$workspaceId;

        if (! array_key_exists($key, $this->roles)) {
            $role = DB::table('workspace_members')
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $user->getKey())
                ->value('role');
            $this->roles[$key] = is_string($role) ? $role : null;
        }

        return $this->roles[$key];
    }
}
