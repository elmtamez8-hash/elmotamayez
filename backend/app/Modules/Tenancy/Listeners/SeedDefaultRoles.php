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
        foreach (Permissions::all() as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

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
}
