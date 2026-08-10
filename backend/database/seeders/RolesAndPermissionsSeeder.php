<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->setPermissionsTeamId(null);

        // Create all permissions (global, team_id = null).
        foreach (Permissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // The global roles (team_id = null), which reach every workspace.
        //
        // finance-admin beside super-admin rather than inside a workspace: credit
        // receipts arrive from every workspace on the platform, so a team-scoped
        // finance officer would have to be a member of every team — a super-admin
        // with extra steps. It is seeded and assigned to NOBODY; delegation is an
        // act, not a default.
        $matrix = RolePermissionMatrix::map();

        foreach (Roles::platformRoles() as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                ->syncPermissions($matrix[$name] ?? []);
        }

        // Pre-create workspace-scoped role definitions so that the per-workspace
        // SeedDefaultRoles listener can use firstOrCreate efficiently.
        // The actual team_id-scoped roles are created when each workspace is created.
    }
}
