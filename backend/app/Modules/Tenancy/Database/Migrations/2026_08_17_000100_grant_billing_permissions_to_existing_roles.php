<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give every EXISTING workspace the two TENANT permissions spec 006 introduced.
 *
 * ⚠️ WITHOUT THIS, 006'S TEACHER-FACING HALF SHIPS AND OPENS FOR NOBODY — the
 * same trap `grant_assessment_permissions_to_existing_roles` was written from,
 * one spec later and unnoticed because it was never looked for. The matrix is a
 * SEED: `SeedDefaultRoles` runs exactly once, when a workspace is created, so
 * adding `billing.balance.view` to `RolePermissionMatrix` reaches every
 * workspace made from today and not one made yesterday.
 *
 * On the machine this was found on, the seeded Demo Teacher's sidebar carried
 * «حصصي» and «بنك الأسئلة» and no «أرصدة الطلاب» and no «وضع الامتحانات»: the
 * balances screen, the exam-mode window and everything reached from them were
 * unreachable for every account that already existed, while the whole PHP suite
 * stayed green — because every fixture creates its workspace after the change.
 * `billing.spec.ts` is what saw it, by walking in from the sidebar as a person
 * does instead of navigating to the url.
 *
 * ⚠️ TWO NAMES, AND THE OTHER EIGHT ARE DELIBERATELY ABSENT. `billing.*` has ten
 * members and eight are PLATFORM permissions held by no tenant role — approving
 * a purchase, minting credits, moving a ceiling, setting the price, reading the
 * collection ladder or the financial audit. Granting them here would hand every
 * teacher the platform's half of the price and let the party who is PAID out of
 * consumed credits be the party who mints them. Which roles get which is read
 * from the matrix rather than listed, so this file cannot disagree with the one
 * a new workspace is seeded from.
 *
 * ⚠️ NAMED ROWS, NOT `syncPermissions()`. Roles are editable from `/admin` since
 * 015, so a re-sync would restore a permission an owner deliberately removed and
 * discard anything they added. Adding named rows touches only what 006 is
 * responsible for.
 */
return new class extends Migration
{
    /** The tenant half of what 006 added. */
    private const ADDED = [
        Permissions::BILLING_BALANCE_VIEW,
        Permissions::BILLING_EXAM_MODE_MANAGE,
    ];

    public function up(): void
    {
        $matrix = RolePermissionMatrix::map();

        foreach (self::ADDED as $permission) {
            $permissionId = $this->permissionId($permission);

            // The role names that are supposed to hold it, per the matrix.
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

        /*
        | ⚠️ AND THE CACHE IS DROPPED, or none of the above is true yet.
        |
        | spatie keeps the whole permission map in the cache store — Redis in
        | production, shared by every worker and every request. Rows written
        | straight into the pivot are invisible until it is dropped, so without
        | this line the deploy finishes, the migration reports success, and every
        | teacher still gets 403 from the screens it just opened.
        */
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
