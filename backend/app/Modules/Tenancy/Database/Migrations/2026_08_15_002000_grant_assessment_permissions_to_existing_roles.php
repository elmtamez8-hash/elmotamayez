<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give every EXISTING workspace the permissions spec 008 introduced.
 *
 * ⚠️ WITHOUT THIS, THE WHOLE OF SPEC 008 SHIPS AND OPENS FOR NOBODY. The matrix
 * is a SEED: `SeedDefaultRoles` runs exactly once, when a workspace is created,
 * so adding `bank.view` to `RolePermissionMatrix` reaches every workspace made
 * from today and not one made yesterday. On the machine this was found on, the
 * demo teacher AND the academy owner both got 403 from
 * `GET /manage/bank/questions` — the question bank, the import, the exam
 * builder and the analysis were all unreachable for every account that already
 * existed, while the entire test suite stayed green, because every fixture
 * creates its workspace after the change. Same trap, same fix, as
 * `grant_roles_manage_to_owners` — see that file's header for why it is raw SQL.
 *
 * ⚠️ THE NEW NAMES ARE LISTED EXPLICITLY RATHER THAN SYNCED FROM THE MATRIX.
 * Roles are editable from `/admin` now, so re-syncing would restore a permission
 * an owner deliberately removed — and `syncPermissions()` would discard anything
 * they added. Adding named rows only touches what this spec is responsible for.
 *
 * ⚠️ AND `questions.manage` IS TAKEN OFF THE ASSISTANT, which is a REVOKE and
 * therefore the one destructive line here. It used to authorise editing the
 * questions inside one exam; after 008 the same name governs the workspace's
 * whole shared bank. Leaving it is not "no change" — it is an assistant silently
 * inheriting every exam the teacher ever wrote.
 */
return new class extends Migration
{
    /**
     * What 008 added. Which ROLES get each one is read from the matrix, so this
     * list never disagrees with the file a new workspace is seeded from.
     *
     * `analytics.cross_teacher.view` is deliberately absent: it is a PLATFORM
     * permission held by no tenant role, and granting it here would hand every
     * teacher the cross-teacher report.
     */
    private const ADDED = [
        Permissions::BANK_VIEW,
        Permissions::GRADING_PERFORM,
        Permissions::GRADING_REVISE,
        Permissions::ASSIGNMENTS_MANAGE,
        Permissions::SUBMISSIONS_GRADE,
        Permissions::ACCOMMODATIONS_MANAGE,
        Permissions::UNLOCK_RULES_MANAGE,
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

        $this->revokeQuestionsManageFromAssistants();

        /*
        | ⚠️ AND THE CACHE IS DROPPED, or none of the above is true yet.
        |
        | spatie keeps the whole permission map in the cache store — Redis in
        | production, shared by every worker and every request. Rows written
        | straight into the pivot are invisible until it is dropped, so without
        | this line the deploy finishes, the migration reports success, and every
        | teacher still gets 403 from the screens it just opened. That was the
        | actual symptom this migration was written from: the rows were there and
        | the answer was still no.
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

        // The assistant's `questions.manage` is NOT restored. Rolling back the
        // schema does not make it safe to hand one person's assistant the whole
        // bank, and a down() that regrants a permission is a rollback that widens
        // access — the one direction a rollback must never move.
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

    private function revokeQuestionsManageFromAssistants(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', Permissions::QUESTIONS_MANAGE)
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', DB::table('roles')->where('name', Roles::ASSISTANT_TEACHER)->pluck('id'))
            ->delete();
    }
};
