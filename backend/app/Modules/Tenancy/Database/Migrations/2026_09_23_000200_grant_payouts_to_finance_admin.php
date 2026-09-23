<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `settlement.payout.execute` → the teamless `finance-admin` role.
 *
 * ⚠️ `RolesAndPermissionsSeeder` writes that role's permissions on
 * `migrate:fresh --seed` alone, never on a deploy — so the matrix line beside
 * this reaches nobody who already exists without it (the precedent and its
 * reasons: `2026_09_03_000200_move_payment_approval_to_the_platform`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', Permissions::SETTLEMENT_PAYOUT_EXECUTE)
            ->where('guard_name', 'web')
            ->value('id');

        $roleId = DB::table('roles')
            ->whereNull('team_id')
            ->where('name', Roles::FINANCE_ADMIN)
            ->value('id');

        // A database that has not seeded permissions yet gets the right map from
        // the seeder directly.
        if ($permissionId === null || $roleId === null) {
            return;
        }

        DB::table('role_has_permissions')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', Permissions::SETTLEMENT_PAYOUT_EXECUTE)->value('id');
        $roleId = DB::table('roles')->whereNull('team_id')->where('name', Roles::FINANCE_ADMIN)->value('id');

        if ($permissionId !== null && $roleId !== null) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
