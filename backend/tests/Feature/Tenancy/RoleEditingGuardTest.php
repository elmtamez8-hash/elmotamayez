<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
| Roles became editable from `/admin`, and this is the rule that made that safe.
|
| ⚠️ THE SCREEN IS NOT THE GUARD. A picker that only offers tenant permissions
| shapes the FORM; the request that follows names permission ids, and nothing
| stops a second request naming different ones. So the refusal lives on the
| model, at `givePermissionTo()` and `syncPermissions()` — the two doors every
| writer uses, the screen and the seeder and a console command alike.
|
| What it prevents in one sentence: a workspace owner ticking
| `billing.pricing.manage` and becoming the platform.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->role = Role::query()->where('name', Roles::TEACHER)
        ->where('team_id', $this->workspace->getKey())
        ->firstOrFail();
});

it('refuses a platform permission on a workspace role', function (): void {
    expect(fn () => $this->role->givePermissionTo(Permissions::BILLING_PRICING_MANAGE))
        ->toThrow(DomainException::class);

    // And through the other door, which is the one the screen uses: a sync sends
    // the WHOLE list, so a single forbidden name arriving among twenty legitimate
    // ones must fail the write rather than be quietly dropped.
    expect(fn () => $this->role->syncPermissions([
        Permissions::COURSES_VIEW,
        Permissions::BILLING_COLLECTION_VIEW,
    ]))->toThrow(DomainException::class);

    expect($this->role->fresh()?->hasPermissionTo(Permissions::BILLING_COLLECTION_VIEW))->toBeFalse();
});

it('allows a tenant permission on a workspace role, which is the whole point of the screen', function (): void {
    $this->role->givePermissionTo(Permissions::MEMBERS_INVITE);

    expect($this->role->fresh()?->hasPermissionTo(Permissions::MEMBERS_INVITE))->toBeTrue();
});

it('leaves the platform roles alone, since holding these is what they are for', function (): void {
    // The teamless roles are reference data seeded at install, not per workspace.
    $this->seed(RolesAndPermissionsSeeder::class);

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // ⚠️ `withoutTeamScope()`, because the scope now keeps teamless roles out of
    // every ordinary query — which is exactly what stops them appearing on the
    // role screen with an edit button. Reaching one on purpose says so.
    $platform = Role::query()->withoutTeamScope()
        ->whereNull('team_id')->where('name', Roles::FINANCE_ADMIN)->firstOrFail();

    $platform->syncPermissions([Permissions::BILLING_PURCHASE_APPROVE, Permissions::ORDERS_VIEW_ALL]);

    expect($platform->fresh()?->hasPermissionTo(Permissions::BILLING_PURCHASE_APPROVE))->toBeTrue();
});

it('derives the platform set instead of listing it, so a new permission is closed by default', function (): void {
    $platform = RolePermissionMatrix::platformPermissions();

    // The six the docs name, all present without anybody having added them here.
    expect($platform)->toContain(
        Permissions::BILLING_COLLECTION_VIEW,
        Permissions::BILLING_AUDIT_VIEW,
        Permissions::BILLING_PRICING_MANAGE,
        Permissions::BILLING_PACKAGES_MANAGE,
        Permissions::BILLING_LIMIT_MANAGE,
        Permissions::BILLING_SETTINGS_MANAGE,
    )
        // And what a teacher plainly needs is not in it.
        ->and($platform)->not->toContain(Permissions::COURSES_VIEW, Permissions::SESSIONS_VIEW);

    // The two halves are a partition: every permission is on exactly one side,
    // which is what makes "the picker offers the complement" a complete answer.
    expect(array_merge($platform, RolePermissionMatrix::tenantPermissions()))
        ->toEqualCanonicalizing(Permissions::all());
});

it('closes a brand-new permission by default rather than opening it', function (): void {
    // The failure this design is aimed at: somebody adds a permission and forgets
    // to classify it. Derivation means the forgotten one lands on the PLATFORM
    // side — refused on a workspace role — instead of becoming tickable by every
    // owner on the platform.
    Permission::findOrCreate('experiments.run', 'web');

    expect(RolePermissionMatrix::platformPermissions())->not->toContain('experiments.run');

    // ...because `Permissions::all()` is the universe, and a name that is not a
    // constant is in neither half. Stated as a test because it is the ONE gap:
    // a permission created outside the constants is invisible to both lists, and
    // `RolesAndPermissionsSeeder` would never create it either.
    expect(RolePermissionMatrix::tenantPermissions())->not->toContain('experiments.run');
});
