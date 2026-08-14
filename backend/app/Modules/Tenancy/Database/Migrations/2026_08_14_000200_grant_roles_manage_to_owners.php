<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing owner the permission that opens the new role screen.
 *
 * ⚠️ THIS IS THE COST OF "THE MATRIX IS A SEED", AND IT IS PAID HERE ONCE PER
 * PERMISSION. `SeedDefaultRoles` runs exactly once, when a workspace is created,
 * so adding `roles.manage` to `RolePermissionMatrix` reaches every workspace made
 * from today and NOT ONE made yesterday. Without this migration the screen ships
 * and opens for nobody who already exists — the quietest kind of broken, because
 * every test passes on a fixture whose workspace was created after the change.
 *
 * A re-seed is not the alternative. `SeedDefaultRoles` calls `syncPermissions()`,
 * which would also DISCARD whatever an owner has since edited on their own roles
 * — the exact drift the decision to let the screen win was meant to allow.
 *
 * ⚠️ AND IT IS RAW ON PURPOSE. `Role::syncPermissions()` would be the polite
 * form, but a migration runs before any of this application's context exists:
 * spatie's team id is unset, the model's global scope would answer for a
 * workspace nobody selected, and the permission cache belongs to a process that
 * has already gone. Two inserts against the pivot say exactly what is meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', Permissions::ROLES_MANAGE)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => Permissions::ROLES_MANAGE,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = DB::table('roles')
            ->where('name', Roles::TENANT_OWNER)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            // insertOrIgnore against the pivot's own primary key: a workspace
            // created between this deploy and this migration already has the row,
            // and a duplicate here would abort a migration over nothing.
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', Permissions::ROLES_MANAGE)
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', DB::table('roles')->where('name', Roles::TENANT_OWNER)->pluck('id'))
            ->delete();
    }
};
