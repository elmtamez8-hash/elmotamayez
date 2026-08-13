<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-021ب · Q-4 — a credit purchase is a sale between the STUDENT and the
| PLATFORM, and `ORDERS_VIEW_ALL` is a teacher permission.
|
| ⚠️ THE HOLE HAD TWO DOORS AND CLOSING ONE WOULD HAVE READ AS CLOSED. Filtering
| the list leaves `show` open, and a teacher who knows the uuid reads the total —
| plus the signed link to the payer's bank receipt, which `OrderResource` hands
| over with it. Both are asserted, in both directions: refused to a teacher,
| allowed to the platform, and always visible to the buyer.
|
| And rejecting is the same decision as reading, for the same reason approving
| already was: a teacher who may refuse a platform sale may cancel a payment made
| to somebody else.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);

    CreditPackage::query()->create([
        'name' => 'حزمة',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);

    $this->creditOrder = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $this->courseOrder = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
});

/** @param  list<string>  $permissions */
function orderReader(string $roleName, array $permissions): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate($roleName, 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = $test->addWorkspaceMember($test->workspace, Roles::TEACHER);
    $user->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $user);

    return $user;
}

it('keeps a credit purchase out of a teacher list that shows every order', function (): void {
    Sanctum::actingAs(orderReader('viewing-teacher', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::PAYMENTS_REJECT,
    ]));

    $uuids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();

    expect($uuids)->toContain($this->courseOrder->uuid)
        ->and($uuids)->not->toContain($this->creditOrder->uuid);
});

it('refuses the same teacher the credit order by uuid', function (): void {
    Sanctum::actingAs(orderReader('viewing-teacher', [Permissions::ORDERS_VIEW_ALL]));

    // The door filtering the list leaves open. Read one at a time, the whole
    // list is reachable anyway — with the receipt link attached.
    $this->getJson("/api/v1/orders/{$this->creditOrder->uuid}")->assertForbidden();

    $this->getJson("/api/v1/orders/{$this->courseOrder->uuid}")->assertOk();
});

it('refuses the same teacher the rejection of a credit order', function (): void {
    Sanctum::actingAs(orderReader('rejecting-teacher', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::PAYMENTS_REJECT,
    ]));

    $this->postJson("/api/v1/orders/{$this->creditOrder->uuid}/reject", ['reason' => 'لا'])
        ->assertForbidden();

    expect($this->creditOrder->refresh()->status)->toBe('pending');
});

it('shows the platform both kinds, by list and by uuid', function (): void {
    Sanctum::actingAs(orderReader('platform-billing', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::BILLING_PURCHASE_APPROVE,
    ]));

    $uuids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();

    expect($uuids)->toContain($this->creditOrder->uuid)
        ->and($uuids)->toContain($this->courseOrder->uuid);

    $this->getJson("/api/v1/orders/{$this->creditOrder->uuid}")->assertOk();
});

it('never hides a buyer their own purchase', function (): void {
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $uuids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();

    // The cut is about who ELSE may read it. A filter written on `kind` alone
    // would take the payer's own receipt away from them.
    expect($uuids)->toContain($this->creditOrder->uuid);

    $this->getJson("/api/v1/orders/{$this->creditOrder->uuid}")->assertOk();
});
