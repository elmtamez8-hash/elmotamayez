<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `courses.trial.choose` (spec 040) and give it to every EXISTING role the
 * matrix says holds it — the teacher and the owner.
 *
 * ⚠️ The same trap `2026_09_24_000100` documents: the matrix is a seed run once
 * per new workspace, so without this every existing teacher would lose the trial
 * switch, and ticking the new box on the roles screen would 500 on a database
 * with no such row. Named rows, never `syncPermissions()`.
 */
return new class extends Migration
{
    private const ADDED = [
        Permissions::COURSES_TRIAL_CHOOSE,
    ];

    public function up(): void
    {
        $matrix = RolePermissionMatrix::map();

        foreach (self::ADDED as $permission) {
            $permissionId = $this->permissionId($permission);

            // Read out of the matrix rather than listed here, so this file cannot
            // disagree with the one a new workspace is seeded from.
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
