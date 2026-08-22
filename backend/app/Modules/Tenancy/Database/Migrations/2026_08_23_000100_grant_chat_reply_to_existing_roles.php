<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the `chat.reply` permission and give it to every EXISTING role the
 * matrix says holds it.
 *
 * ⚠️ THE MATRIX IS A SEED, SO ADDING A CONSTANT REACHES NOBODY WHO ALREADY
 * EXISTS. `SeedDefaultRoles` runs exactly once, when a workspace is created —
 * the same trap `grant_assessment_permissions_to_existing_roles` was written for
 * in 008 and `grant_billing_permissions_to_existing_roles` in 006, hit twice
 * because nothing in a green suite can see it: every fixture creates its
 * workspace AFTER the change.
 *
 * ⚠️ AND HERE IT BITES BEFORE THE FEATURE EVEN EXISTS, which the two precedents
 * did not. The roles screen's vocabulary is
 * `RolePermissionMatrix::tenantPermissions()` — derived from CODE, not from the
 * table — so on any database created before today the box «الردّ — محادثات
 * الطلاب» renders the moment this deploys, while `permissions` holds no such
 * row. Ticking it reaches `Permission::findByName()` and raises
 * `PermissionDoesNotExist`: a 500 on the owner's own settings screen, over a
 * feature nobody has shipped yet.
 *
 * ⚠️ NAMED ROWS, NOT `syncPermissions()`. Roles are editable from `/admin` since
 * 015, so a re-sync would restore a permission an owner deliberately removed and
 * discard anything they added.
 */
return new class extends Migration
{
    private const ADDED = [
        Permissions::CHAT_REPLY,
    ];

    public function up(): void
    {
        $matrix = RolePermissionMatrix::map();

        foreach (self::ADDED as $permission) {
            $permissionId = $this->permissionId($permission);

            // The role names that are supposed to hold it, per the matrix — read
            // rather than listed, so this file cannot disagree with the one a new
            // workspace is seeded from.
            $roleNames = [];

            foreach ($matrix as $roleName => $permissions) {
                if (in_array($permission, $permissions, true)) {
                    $roleNames[] = $roleName;
                }
            }

            if ($roleNames === []) {
                continue;
            }

            $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');

            foreach ($roleIds as $roleId) {
                // insertOrIgnore against the pivot's own primary key: a workspace
                // created between the deploy and this migration already holds the
                // row, and a duplicate would abort the migration over nothing.
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        // ⚠️ And the cache is dropped, or none of the above is true yet — spatie
        // keeps the whole map in the cache store, shared by every worker.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ADDED as $permission) {
            $permissionId = DB::table('permissions')->where('name', $permission)->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionId(string $name): int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
