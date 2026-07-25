<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Tenancy\Support\Permissions;
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

        // Create the global super-admin role and grant all permissions.
        $superAdmin = Role::firstOrCreate(['name' => Roles::SUPER_ADMIN, 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permissions::all());

        // Pre-create workspace-scoped role definitions so that the per-workspace
        // SeedDefaultRoles listener can use firstOrCreate efficiently.
        // The actual team_id-scoped roles are created when each workspace is created.
    }
}
