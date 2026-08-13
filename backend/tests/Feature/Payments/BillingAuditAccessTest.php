<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-029 — the trail is platform-level, and the workspace owner is refused.
|
| ⚠️ THE OWNER IS THE CASE THAT MATTERS, not the anonymous visitor. `activity_log`
| has no `workspace_id` column and no global scope, so this endpoint is
| platform-wide by construction: a teacher allowed in would read every decision
| taken about every student on the platform, including the ones belonging to the
| teacher down the road. The constitution requires exactly this assertion for a
| platform-owned entity, and the highest tenant role is the strongest form of it.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $this->payment = PaymentTransaction::create([
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $order->getKey(),
        'provider' => 'manual',
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'REF-ACCESS',
    ]);
});

it('refuses the workspace owner, who holds every tenant permission there is', function (): void {
    Sanctum::actingAs($this->owner);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->getJson('/api/v1/admin/payments/audit')->assertForbidden();
    $this->getJson("/api/v1/admin/payments/audit/{$this->payment->uuid}")->assertForbidden();
});

it('refuses a student outright', function (): void {
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $this->getJson('/api/v1/admin/payments/audit')->assertForbidden();
});

it('refuses an anonymous request before it reaches the permission at all', function (): void {
    $this->getJson('/api/v1/admin/payments/audit')->assertUnauthorized();
});

it('lets the platform in', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('platform-auditor', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_AUDIT_VIEW, 'web'));

    $auditor = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $auditor->assignRole($role);
    $this->setCurrentWorkspace($this->workspace, $auditor);

    Sanctum::actingAs($auditor);

    $this->getJson('/api/v1/admin/payments/audit')->assertOk();
    $this->getJson("/api/v1/admin/payments/audit/{$this->payment->uuid}")->assertOk();
});

it('is reachable by the super admin, who holds every platform permission', function (): void {
    $platform = User::factory()->create(['is_super_admin' => true]);

    Sanctum::actingAs($platform);

    // The assignment mechanism: `Permissions::all()` reaches super-admin through
    // `RolePermissionMatrix`, and a constant left out of that array is a
    // permission nobody on the platform holds — including this account.
    $this->getJson('/api/v1/admin/payments/audit')->assertOk();
});
