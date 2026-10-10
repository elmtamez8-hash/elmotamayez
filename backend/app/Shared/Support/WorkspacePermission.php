<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Models\User;

/**
 * «Does this person hold this permission IN THAT workspace?» — asked about the
 * workspace a row belongs to, never about the reader's current one.
 *
 * ⛔ `$user->hasPermissionTo()` ON ITS OWN ANSWERS FOR THE AMBIENT TEAM, which
 * `EnsureCurrentWorkspace` sets from the reader's own current workspace. A
 * teacher who owns workspace A and is a mere member of B passed every B door
 * that paired «member of B» with «holds chat.reply» — the membership was asked
 * of B and the permission of A (security scan 2026-10-10, F1 · F3 · F8).
 *
 * The loaded `roles`/`permissions` relations are dropped BEFORE (spatie reads
 * them, and once loaded they answer for whichever team loaded them) and AFTER,
 * so the rest of the request does not inherit B's answer either.
 */
class WorkspacePermission
{
    public static function holds(User $user, int $workspaceId, string $permission): bool
    {
        return app(WorkspaceContext::class)->forWorkspace($workspaceId, static function () use ($user, $permission): bool {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            try {
                return $user->hasPermissionTo($permission);
            } finally {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }
}
