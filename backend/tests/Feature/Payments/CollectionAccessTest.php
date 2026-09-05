<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-033 — no teacher and no assistant reaches what a student paid, or any
| collection total. The export carries the same restriction (FR-034).
|
| ⚠️ THE WORKSPACE OWNER IS THE CASE THAT MATTERS, not the anonymous visitor. The
| owner holds every tenant permission there is, so a refusal for them is the
| strongest statement the fixture can make — and the constitution §I requires
| exactly this assertion for every platform permission.
|
| ⚠️ AND THE RECONCILIATION ROUTE IS TESTED HERE TOO. `PaymentReconciliationRun`
| is a platform-owned entity behind the same permission, and it was the only one
| of the three routes without an owner-is-refused case. Its WRITE guard is
| deliberately absent rather than missing: the table is written by an hourly job
| and by no user, so there is no write surface to guard — «not applicable» being
| a decision that gets written down, not a gap that gets left.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->window = 'from='.now()->subWeek()->toDateString().'&to='.now()->toDateString();
});

/** Every route this phase puts behind `BILLING_COLLECTION_VIEW`. */
function collectionRoutes(): array
{
    $window = test()->window;

    return [
        '/api/v1/admin/payments/collection?'.$window,
        '/api/v1/admin/payments/collection/export?'.$window,
        '/api/v1/admin/payments/reconciliation',
        /*
        | ⚠️ THE CREDIT SWEEP JOINED THIS FAMILY ON 2026-09-05, AND IT WAS BEHIND
        | THE WRONG DOOR ENTIRELY. It asked `billing.pricing.manage` — a READ of
        | the platform's ledger behind the permission for EDITING the platform's
        | cut — so the officer who opens the payments sweep every morning was
        | refused its twin, and granting them the twin meant handing over the six
        | pricing keys with it. Listed here rather than tested apart, because the
        | five cases above are exactly the five this route needs.
        */
        '/api/v1/admin/billing/reconciliation',
    ];
}

it('refuses the workspace owner, who holds every tenant permission there is', function (): void {
    Sanctum::actingAs($this->owner);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    foreach (collectionRoutes() as $route) {
        $this->getJson($route)->assertForbidden();
    }
});

it('refuses the teaching assistant on every route including the export', function (): void {
    Sanctum::actingAs($this->assistant);
    $this->setCurrentWorkspace($this->workspace, $this->assistant);

    foreach (collectionRoutes() as $route) {
        $this->getJson($route)->assertForbidden();
    }
});

it('refuses a student outright', function (): void {
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    foreach (collectionRoutes() as $route) {
        $this->getJson($route)->assertForbidden();
    }
});

it('refuses an anonymous request before it reaches the permission at all', function (): void {
    foreach (collectionRoutes() as $route) {
        $this->getJson($route)->assertUnauthorized();
    }
});

it('lets a holder of the platform permission in, on all three', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('platform-collector', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_COLLECTION_VIEW, 'web'));

    $reader = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $reader->assignRole($role);
    $this->setCurrentWorkspace($this->workspace, $reader);

    Sanctum::actingAs($reader);

    foreach (collectionRoutes() as $route) {
        $this->get($route)->assertOk();
    }
});

it('is reachable by the super admin, who holds every platform permission', function (): void {
    // The assignment mechanism: `Permissions::all()` reaches super-admin through
    // `RolePermissionMatrix`, and a constant left out of that array is a
    // permission nobody on the platform holds — including this account.
    Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

    foreach (collectionRoutes() as $route) {
        $this->get($route)->assertOk();
    }
});

it('refuses a period it cannot read before it refuses nothing at all', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('platform-collector', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_COLLECTION_VIEW, 'web'));

    $reader = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $reader->assignRole($role);
    $this->setCurrentWorkspace($this->workspace, $reader);

    Sanctum::actingAs($reader);

    // No period at all, and a period that ends before it starts. Both are 422
    // rather than a report of everything ever collected: an unbounded default is
    // a full scan of the fastest-growing table in the product, chosen by nobody.
    $this->getJson('/api/v1/admin/payments/collection')->assertStatus(422);
    $this->getJson('/api/v1/admin/payments/collection?from=2026-08-10&to=2026-08-01')->assertStatus(422);
});
