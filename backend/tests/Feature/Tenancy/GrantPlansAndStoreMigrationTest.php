<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
| A workspace created before spec 011 was seeded without these three, and on
| production the teacher read «هذه الصفحة ليست لك» on «باقات الاشتراك». The
| fixture strips them from a fresh workspace's roles — the shape that database
| has — and the migration must put them back on the roles the matrix names.
*/

const GRANTED_BY_011 = [
    Permissions::PLANS_MANAGE,
    Permissions::STORE_ITEMS_MANAGE,
    Permissions::STORE_SHIPMENTS_MANAGE,
];

function rolePermissionNames(int $workspaceId, string $role): array
{
    return DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.team_id', $workspaceId)
        ->where('roles.name', $role)
        ->pluck('permissions.name')
        ->all();
}

it('gives a pre-011 workspace the plans and store permissions back', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    DB::table('role_has_permissions')
        ->whereIn('permission_id', DB::table('permissions')->whereIn('name', GRANTED_BY_011)->pluck('id'))
        ->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(rolePermissionNames($workspace->getKey(), 'tenant-owner'))->not->toContain(Permissions::PLANS_MANAGE);

    (require base_path('app/Modules/Tenancy/Database/Migrations/2026_09_24_000100_grant_plans_and_store_to_existing_roles.php'))->up();

    foreach (['tenant-owner', 'teacher'] as $role) {
        expect(rolePermissionNames($workspace->getKey(), $role))->toContain(...GRANTED_BY_011);
    }

    // The matrix keeps them off the assistant; the migration reads the matrix.
    expect(rolePermissionNames($workspace->getKey(), 'assistant-teacher'))
        ->not->toContain(Permissions::PLANS_MANAGE);
});
