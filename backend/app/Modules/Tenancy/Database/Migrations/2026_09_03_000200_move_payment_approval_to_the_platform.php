<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `payments.approve` و`payments.reject` تتركانِ أدوارَ المستأجرِ إلى المنصّة.
 *
 * ⚠️ THE MIRROR OF EVERY «grant to existing roles» MIGRATION IN THIS DIRECTORY,
 * AND IT MATTERS FOR THE SAME REASON THEY DO. `SeedDefaultRoles` runs once, at
 * workspace creation, so editing `RolePermissionMatrix` reaches nobody who
 * already exists — the trap this repository has now hit six times. Removing a
 * constant has the identical shape running backwards: every workspace already on
 * the platform keeps granting a permission the matrix no longer names, and the
 * suite stays green because every fixture builds its workspace after the change.
 *
 * ⚠️ AND THE INTERMEDIATE STATE IS NOT SHIPPABLE, WHICH IS WHY THIS DEPLOYS WITH
 * THE MATRIX CHANGE AND NOT AFTER IT. `Tenancy\Models\Role` throws when a
 * PLATFORM permission is attached to a role carrying a `team_id`, and these two
 * become platform-level by derivation the moment they leave `$teacher`. A
 * workspace created in the window between the two would take that throw inside
 * `SeedDefaultRoles`, i.e. inside `CreateWorkspace`.
 *
 * ⚠️ THE REVOKE IS SCOPED TO ROLES WITH A `team_id`, and the teamless rows are
 * left alone deliberately: `super-admin` holds every permission through
 * `Permissions::all()` and `finance-admin` is about to hold these two on purpose.
 * A blanket delete on `permission_id` — the shape the `down()` of the grant
 * migrations use, where it is correct because nothing else held the row — would
 * take the approval away from the platform as well and leave nobody at all able
 * to approve a transfer.
 *
 * Measured on production before writing: **12 rows** — `teacher` and
 * `tenant-owner` across three workspaces (team ids 1, 5, 6), two permissions
 * each. The two teamless `super-admin` rows stay.
 *
 * ⚠️ AND THE GRANT HALF IS NOT OPTIONAL. `RolesAndPermissionsSeeder` is what
 * gives `finance-admin` its permissions, and it runs on `migrate:fresh --seed`
 * alone — never on a deploy. Without these two rows the officer inherits nothing
 * and the approval reaches `super-admin` by itself, which is the single-account
 * bottleneck `FR-026` exists to avoid.
 */
return new class extends Migration
{
    private const MOVED = [
        Permissions::PAYMENTS_APPROVE,
        Permissions::PAYMENTS_REJECT,
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::MOVED)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            // Nothing to move on a database that has not seeded permissions yet;
            // `RolesAndPermissionsSeeder` will write the correct map directly.
            return;
        }

        // ⚠️ `whereIn` over the ids of roles that carry a team, not a join with a
        // `whereNotNull` on the delete itself: MySQL and SQLite disagree about
        // deleting through a join, and this repository runs its whole suite on
        // the second one.
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
                // insertOrIgnore against the pivot's own primary key — the same
                // idiom the grant migrations beside this one use, and for the
                // same reason: a re-run must not abort over a row it wrote.
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $financeAdminId,
                ]);
            }
        }

        // ⚠️ Or none of the above is true yet: spatie keeps the whole map in the
        // cache store, shared by every worker, and a revoke that is not flushed
        // reads as not-applied until something else happens to drop it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * ⚠️ THIS RESTORES THE OLD GRANT AND SAYS SO, because a rollback that quietly
     * did nothing would leave a database whose matrix and whose role rows
     * disagree — the exact state this migration exists to end. It re-attaches the
     * two permissions to every `teacher` and `tenant-owner` role that carries a
     * team, which is what they held before, and drops them from the finance role.
     */
    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::MOVED)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        $tenantRoleIds = DB::table('roles')
            ->whereNotNull('team_id')
            ->whereIn('name', [Roles::TEACHER, Roles::TENANT_OWNER])
            ->pluck('id')
            ->all();

        foreach ($permissionIds as $permissionId) {
            foreach ($tenantRoleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        $financeAdminId = DB::table('roles')
            ->whereNull('team_id')
            ->where('name', Roles::FINANCE_ADMIN)
            ->value('id');

        if ($financeAdminId !== null) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->where('role_id', $financeAdminId)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
