<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Events\WorkspaceCreated;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the workspace-scoped roles (with their permissions) when a new workspace
 * is created. Runs synchronously within the creating transaction. Ensures all
 * permissions exist first so workspace creation is self-contained.
 */
// ⚠️ OURS, NOT SPATIE'S — see the note in RolesAndPermissionsSeeder. The
// platform-permission guard on `syncPermissions()` below only exists if the
// class holding it is the class being called.
class SeedDefaultRoles
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(WorkspaceCreated $event): void
    {
        $workspaceId = $event->workspace->getKey();

        // Ensure all permissions exist (global, team_id = null) before syncing.
        $this->ensurePermissionsExist();

        $previousTeam = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($workspaceId);

        try {
            foreach (Roles::workspaceRoles() as $roleName) {
                $role = Role::firstOrCreate([
                    'name' => $roleName,
                    'team_id' => $workspaceId,
                    'guard_name' => 'web',
                ]);

                $permissions = RolePermissionMatrix::map()[$roleName] ?? [];
                $role->syncPermissions($permissions);
            }
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * The hundred global permissions, written in ONE statement instead of a
     * hundred `firstOrCreate` round trips.
     *
     * ⚠️ THIS WAS 200 OF THE 240 QUERIES ONE WORKSPACE CREATION COSTS. Measured
     * 2026-09-07: `createWorkspaceWithOwner()` took 483 ms and 240 queries, of
     * which the loop below replaced was a SELECT and an INSERT for each of
     * `Permissions::all()` — a hundred rows that are global (`team_id = null`),
     * identical for every workspace on the platform, and unchanged since the
     * release that declared them. The test suite pays it 821 times.
     *
     * ⚠️ AND `insertOrIgnore` IS SAFE HERE FOR A REASON THAT MUST BE RE-CHECKED
     * IF THE TABLE CHANGES. This repository records that `insertOrIgnore` writes
     * a row without booting the model, so `HasUuid` never fires — and on MySQL
     * the resulting NOT NULL violation is downgraded to a warning, `''` is
     * stored, and every later row collides on `unique(uuid)` and is silently
     * skipped. `permissions` has NO uuid column: it is `id`, `name`,
     * `guard_name` and nullable timestamps, with `unique(name, guard_name)` —
     * and that index IS the idempotency guard, the same shape
     * `CreditLedger::writeEntry()` relies on. The timestamps are passed
     * EXPLICITLY because the model layer is not there to supply them.
     *
     * ⚠️ AND THE CACHE MUST BE FORGOTTEN WHEN WE ACTUALLY WRITE. `syncPermissions()`
     * resolves a name through `Permission::findByName()`, which reads the
     * `PermissionRegistrar` cache; `firstOrCreate` used to invalidate it as a side
     * effect of creating the model, and a raw insert does not. Without this the
     * very next line throws `PermissionDoesNotExist` about a row that was just
     * written. It is forgotten only when something was inserted, so the ordinary
     * case — every permission already present — costs one SELECT and leaves every
     * later `can()` in the request reading a warm cache.
     */
    private function ensurePermissionsExist(): void
    {
        $names = Permissions::all();

        $existing = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($names, $existing));

        if ($missing === []) {
            return;
        }

        $now = now();

        Permission::query()->insertOrIgnore(array_map(static fn (string $name): array => [
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ], $missing));

        $this->registrar->forgetCachedPermissions();
    }
}
