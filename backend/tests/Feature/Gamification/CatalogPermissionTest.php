<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Role as TenantRole;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The catalogue is the platform's, not the teacher's (FR-002 · plan §المبدأ الخامس).
 *
 * ⚠️ WHAT AN ACTION IS WORTH ORDERS THE WHOLE PLATFORM. A teacher who could raise
 * "attended a session" from 10 to 500 would seat their own students at the top of
 * the subject, the grade and the platform boards, and everyone else's below them.
 * Same reasoning as `billing.limit.manage`: it is not their number to move.
 *
 * The guard is DERIVED, which is why it needs a test at all —
 * `platformPermissions()` is `all()` minus everything any workspace role holds,
 * so a constant is platform-level by ABSENCE. Absence is exactly the kind of
 * property that decays without something watching it.
 */
it('keeps the catalogue and the taxonomy out of every workspace role', function (): void {
    $platform = RolePermissionMatrix::platformPermissions();

    expect($platform)->toContain(Permissions::GAMIFICATION_CATALOG_MANAGE)
        ->and($platform)->toContain(Permissions::TAXONOMY_MANAGE);

    // The highest tenant role there is, checked by name rather than by picking a
    // role at random: if the owner does not hold it, nobody below them does.
    $owner = RolePermissionMatrix::map()[Roles::TENANT_OWNER];

    expect($owner)->not->toContain(Permissions::GAMIFICATION_CATALOG_MANAGE)
        ->and($owner)->not->toContain(Permissions::TAXONOMY_MANAGE);
});

/*
 * The other half: the model refuses the write however it is reached.
 *
 * A filtered picker shapes one request and not the next — the roles screen is
 * only one door — so the refusal lives on Tenancy\Models\Role, which throws when a
 * platform permission reaches a role that carries a team_id.
 */
it('refuses to attach a platform permission to a workspace role', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $role = TenantRole::findOrCreate('custom-teacher', 'web');
    $permission = Permission::findOrCreate(Permissions::GAMIFICATION_CATALOG_MANAGE, 'web');

    expect(fn () => $role->givePermissionTo($permission))->toThrow(DomainException::class);
});

/*
 * And the three that ARE the teacher's must be listed in the matrix.
 *
 * ⚠️ THIS IS THE ONE THAT FAILS THE SEEDER, NOT THE REVIEW. Because
 * platformPermissions() subtracts, a tenant permission left out of every array is
 * classified platform-level — and then Tenancy\Models\Role THROWS the moment
 * RolesAndPermissionsSeeder tries to grant it to the teacher role. The symptom is
 * `php artisan db:seed` dying, a long way from the omission that caused it.
 */
it('lists the teacher-side gamification permissions as tenant permissions', function (): void {
    $tenant = RolePermissionMatrix::tenantPermissions();

    expect($tenant)->toContain(Permissions::REWARDS_MANAGE)
        ->and($tenant)->toContain(Permissions::REDEMPTIONS_FULFILL)
        ->and($tenant)->toContain(Permissions::PROGRESS_VIEW_STUDENT);
});

it('seeds every gamification permission, so even the super admin can hold them', function (): void {
    // A constant outside all() is never seeded, so nothing holds it and every
    // check against it fails — for the super admin too.
    expect(Permissions::all())
        ->toContain(Permissions::GAMIFICATION_CATALOG_MANAGE)
        ->toContain(Permissions::TAXONOMY_MANAGE)
        ->toContain(Permissions::REWARDS_MANAGE)
        ->toContain(Permissions::REDEMPTIONS_FULFILL)
        ->toContain(Permissions::PROGRESS_VIEW_STUDENT);
});
