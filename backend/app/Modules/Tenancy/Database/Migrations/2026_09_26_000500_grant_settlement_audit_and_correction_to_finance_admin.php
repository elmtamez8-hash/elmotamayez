<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `settlement.audit.view` + `settlement.period.manage` → the teamless
 * `finance-admin` role (owner decision 2026-09-26).
 *
 * The finance officer reads the settlement audit and corrects a teaching unit
 * from /admin. `settlement.period.manage` is what `TeachingUnitPolicy::reverse()`
 * asks — there is no narrower permission for the correction — and it also lets
 * the officer close a period, which is the same trust: whoever may freeze a
 * period's totals is who may say a unit should not have been in them.
 *
 * ⚠️ `RolesAndPermissionsSeeder` writes that role's permissions on
 * `migrate:fresh --seed` alone, never on a deploy — so the matrix line beside
 * this reaches nobody who already exists without it (the precedent and its
 * reasons: `2026_09_03_000200_move_payment_approval_to_the_platform`).
 *
 * ⚠️ AND BOTH STAY PLATFORM PERMISSIONS. No workspace role holds either in the
 * matrix; any tenant row that holds one anyway (a hand edit, an old seed) is
 * removed here, because a workspace role may never hold a platform permission.
 */
return new class extends Migration
{
    private const GRANTED = [
        Permissions::SETTLEMENT_AUDIT_VIEW,
        Permissions::SETTLEMENT_PERIOD_MANAGE,
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::GRANTED)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        // A database that has not seeded permissions yet gets the right map from
        // the seeder directly.
        if ($permissionIds === []) {
            return;
        }

        $tenantRoleIds = DB::table('roles')->whereNotNull('team_id')->pluck('id')->all();

        if ($tenantRoleIds !== []) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->whereIn('role_id', $tenantRoleIds)
                ->delete();
        }

        $financeAdminId = DB::table('roles')
            ->whereNull('team_id')
            ->where('name', Roles::FINANCE_ADMIN)
            ->value('id');

        if ($financeAdminId !== null) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $financeAdminId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Takes the grant back. The tenant rows removed above were never meant to exist. */
    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', self::GRANTED)->pluck('id')->all();
        $financeAdminId = DB::table('roles')->whereNull('team_id')->where('name', Roles::FINANCE_ADMIN)->value('id');

        if ($permissionIds !== [] && $financeAdminId !== null) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->where('role_id', $financeAdminId)
                ->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
