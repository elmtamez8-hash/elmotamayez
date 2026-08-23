<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the `chat.moderate` permission and give it to every EXISTING role the
 * matrix says holds it.
 *
 * ⚠️ THE SAME TRAP AS `chat.reply` ONE FILE UP, AND FOR THE SAME REASON: the
 * matrix is a SEED that `SeedDefaultRoles` runs exactly once, when a workspace is
 * created — so adding a constant reaches nobody who already exists, while the
 * suite stays green because every fixture creates its workspace after the change.
 * Third time in this repository (006, 008, 010).
 *
 * ⚠️ AND THE ROLES SCREEN BITES FIRST. Its vocabulary is
 * `RolePermissionMatrix::tenantPermissions()`, derived from CODE — so «حذف
 * رسالةٍ وحظر مشارِك» renders the moment this deploys, and ticking it on a
 * database with no such row raises `PermissionDoesNotExist`: a 500 on the owner's
 * own settings screen.
 *
 * ⚠️ NAMED ROWS, NOT `syncPermissions()` — roles are editable from `/admin` since
 * 015, so a re-sync would restore what an owner removed and discard what they
 * added.
 */
return new class extends Migration
{
    private const ADDED = [
        Permissions::CHAT_MODERATE,
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
