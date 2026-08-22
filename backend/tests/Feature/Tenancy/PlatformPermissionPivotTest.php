<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
| A workspace role may never hold a platform permission — measured at the PIVOT.
|
| ⚠️ `Tenancy\Models\Role` THROWS ON `givePermissionTo()`, AND THAT GUARD HAS A
| DOOR BESIDE IT. The shipped migration idiom in this repository writes
| `DB::table('role_has_permissions')->insertOrIgnore([...])` directly — four
| migrations do, because a migration must not depend on a model whose class may
| have changed since it was written. A query builder insert boots no model, so the
| refusal in `Role` is not consulted at all, and a single wrong row hands one
| teacher the platform's half of the price with no error anywhere.
|
| So this file asserts on the TABLE after every migration and seeder has run,
| rather than on the model's behaviour — which `RoleEditingGuardTest` already
| covers from the other side. Neither is sufficient alone: the model guard is the
| one that stops a request, and this one is the only thing that sees a migration.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    /*
    | A workspace, because the teamed roles do not exist until one is created:
    | `SeedDefaultRoles` writes them on `WorkspaceCreated`. Without it the deny
    | direction below sweeps an empty table and is green for the wrong reason.
    */
    [$this->workspace] = $this->createWorkspaceWithOwner();
});

it('grants no platform permission to any role belonging to a workspace', function (): void {
    $platform = RolePermissionMatrix::platformPermissions();

    expect($platform)->not->toBeEmpty();

    /*
    | Teamed roles only. The platform roles — `compliance-officer`, `finance-admin`
    | — are exactly the rows with a null `team_id`, and they are SUPPOSED to hold
    | these permissions; a query that swept them up would fail on correct data and
    | be "fixed" by weakening the predicate.
    */
    $offenders = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->whereNotNull('roles.team_id')
        ->whereIn('permissions.name', $platform)
        ->select(['roles.name as role', 'permissions.name as permission'])
        ->get();

    expect($offenders)->toBeEmpty(
        'دورٌ داخل مساحةِ عملٍ يحمل صلاحيةً منصّية: '.
        $offenders->map(fn (object $row): string => "{$row->role} ← {$row->permission}")->implode(' · '),
    );
});

it('still lets a workspace role hold the tenant permissions it is meant to', function (): void {
    /*
    | The ALLOW direction, because a deny-only test passes just as well against a
    | join that matches nothing — a typo in a table name, a `whereNotNull` on the
    | wrong column, a permission set that came back empty. `taxonomy.manage` shipped
    | classified, seeded, asserted platform-level, and read by no file at all; what
    | catches that shape is asking whether the thing that should work does.
    */
    $teacher = Role::query()->whereNotNull('team_id')->where('name', Roles::TEACHER)->first();

    expect($teacher)->not->toBeNull()
        ->and($teacher?->permissions->pluck('name')->all())->toContain(Permissions::COURSES_CREATE);
});
