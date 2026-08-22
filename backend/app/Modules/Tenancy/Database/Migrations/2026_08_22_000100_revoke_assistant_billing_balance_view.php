<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Take `billing.balance.view` off every EXISTING `assistant-teacher` role.
 *
 * Spec 010 FR-003 refuses an assistant every financial surface there is, and this
 * was the one financial permission the role held by default — granted to every
 * workspace on 2026-08-17 by `grant_billing_permissions_to_existing_roles`, which
 * read the matrix and found `assistant-teacher` in it.
 *
 * ⚠️ THE MATRIX IS A SEED, SO EDITING IT REACHES NOBODY WHO ALREADY EXISTS.
 * `SeedDefaultRoles` runs exactly once, when a workspace is created — the mirror
 * of the trap that migration was written for, walked in the opposite direction.
 * Without this file the change ships, every workspace made from today is correct,
 * and every workspace made yesterday keeps the grant, invisibly, while the whole
 * PHP suite stays green because every fixture creates its workspace after the
 * change.
 *
 * ⚠️ `team_id IS NOT NULL` IS THE WHOLE PREDICATE, and it is not defensive
 * decoration. A row named `assistant-teacher` with no team is a PLATFORM role by
 * this codebase's own definition, and platform standing is granted by naming a
 * person in `platform_staff`, never by a workspace seeder. Deleting one here
 * would be this migration reaching outside what it is responsible for.
 *
 * ⚠️ AND NOTHING IS RESTORED ON `down()`. The permission is still tickable from
 * the roles screen — that is the point of moving it to `$teacher` rather than
 * deleting it — so an owner may have granted it back deliberately, to a named
 * assistant, after this ran. A `down()` that re-granted it to every assistant
 * role would be indistinguishable from that decision and would overwrite it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', Permissions::BILLING_BALANCE_VIEW)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')
            ->where('name', Roles::ASSISTANT_TEACHER)
            ->whereNotNull('team_id')
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->delete();

        /*
        | ⚠️ AND THE CACHE IS DROPPED, or none of the above is true yet.
        |
        | spatie keeps the whole permission map in the cache store — Redis in
        | production, shared by every worker and every request. Rows deleted
        | straight out of the pivot stay live until it is dropped, so without this
        | line the deploy finishes, the migration reports success, and every
        | assistant keeps reading balances until the cache happens to expire. The
        | financial wall spec 010 builds on top would then be tested against a
        | grant that is stale rather than gone.
        */
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately empty — see the class docblock.
    }
};
