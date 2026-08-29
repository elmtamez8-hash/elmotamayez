<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 011 · T024 — the two kinds `OrderKind` gained, on the platform's side of
| every door `credits` already sits behind.
|
| ⚠️ THE STORE SALE IS THE ONE THAT READS BACKWARDS, and that is why it is the
| case worth writing down. The goods are the teacher's, the price is the
| teacher's (Q-4 of this spec's second session), and the money ends up mostly
| theirs — so the obvious reading is that the teacher approves it. That reading
| is exactly the defect: approving a manual transfer is WITNESSING THAT THE MONEY
| ARRIVED, and the seller is the last person who should sign for their own
| receipt. `PAYMENTS_APPROVE` is in the teacher's array and
| `belongsToCurrentWorkspace()` is a bar the seller clears by definition, so
| without this branch the teacher marks their own sale paid and the platform's
| commission is owed against a transfer nobody checked.
|
| ⚠️ AND THE LIST CUT IS TESTED IN BOTH DIRECTIONS BECAUSE IT WAS A DENYLIST.
| Both readers were `where('kind', '!=', Credits)` — true of an enum with two
| cases, and a silent widening at four. Asserting only that the platform SEES the
| new kinds would pass just as well against the old predicate; asserting only the
| teacher's refusal would pass against a `whereIn` that names nothing at all.
|
| `storeOrderReader()` rather than `orderReader()`: Pest loads every test file
| into one process, so a second definition of that name is a fatal redeclare.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    // No `course_id`: a book is not bought against a course, and the column is
    // nullable. A subscription is not either — it buys a span of time.
    $this->storeOrder = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'kind' => OrderKind::Store,
        'amount_minor' => 5_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $this->subscriptionOrder = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'kind' => OrderKind::Subscription,
        'amount_minor' => 30_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $this->courseOrder = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
});

/** @param  list<string>  $permissions */
function storeOrderReader(string $roleName, array $permissions): User
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

it('refuses a teacher the approval of a sale from their own store', function (): void {
    Sanctum::actingAs(storeOrderReader('approving-teacher', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::PAYMENTS_APPROVE,
    ]));

    // The goods are theirs and the permission is theirs. What is refused is
    // signing that the transfer landed.
    $this->postJson("/api/v1/orders/{$this->storeOrder->uuid}/approve")->assertForbidden();

    expect($this->storeOrder->refresh()->status)->toBe('pending');
});

it('refuses the same teacher the approval of a subscription in their workspace', function (): void {
    Sanctum::actingAs(storeOrderReader('approving-teacher', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::PAYMENTS_APPROVE,
    ]));

    $this->postJson("/api/v1/orders/{$this->subscriptionOrder->uuid}/approve")->assertForbidden();

    expect($this->subscriptionOrder->refresh()->status)->toBe('pending');
});

it('still lets that teacher approve an ordinary course order', function (): void {
    Sanctum::actingAs(storeOrderReader('approving-teacher', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::PAYMENTS_APPROVE,
    ]));

    // The positive control. Without it every assertion above is satisfied by a
    // branch that refuses everything — including the path that has shipped since
    // spec 001 and which this change must not touch.
    $this->postJson("/api/v1/orders/{$this->courseOrder->uuid}/approve")->assertOk();
});

it('refuses a teacher the rejection of a store sale and a subscription', function (): void {
    Sanctum::actingAs(storeOrderReader('rejecting-teacher', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::PAYMENTS_REJECT,
    ]));

    // Rejecting is the same decision as approving, reached from the other side:
    // a teacher who may refuse it may cancel a payment made to somebody else.
    $this->postJson("/api/v1/orders/{$this->storeOrder->uuid}/reject", ['reason' => 'لا'])
        ->assertForbidden();
    $this->postJson("/api/v1/orders/{$this->subscriptionOrder->uuid}/reject", ['reason' => 'لا'])
        ->assertForbidden();

    expect($this->storeOrder->refresh()->status)->toBe('pending')
        ->and($this->subscriptionOrder->refresh()->status)->toBe('pending');
});

it('keeps both new kinds out of a teacher list that shows every order', function (): void {
    Sanctum::actingAs(storeOrderReader('viewing-teacher', [Permissions::ORDERS_VIEW_ALL]));

    $uuids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();

    expect($uuids)->toContain($this->courseOrder->uuid)
        ->and($uuids)->not->toContain($this->storeOrder->uuid)
        ->and($uuids)->not->toContain($this->subscriptionOrder->uuid);
});

it('refuses the same teacher either of them by uuid', function (): void {
    Sanctum::actingAs(storeOrderReader('viewing-teacher', [Permissions::ORDERS_VIEW_ALL]));

    // The door a list filter leaves open — read one at a time, the whole list is
    // reachable anyway, receipt link and all.
    $this->getJson("/api/v1/orders/{$this->storeOrder->uuid}")->assertForbidden();
    $this->getJson("/api/v1/orders/{$this->subscriptionOrder->uuid}")->assertForbidden();

    $this->getJson("/api/v1/orders/{$this->courseOrder->uuid}")->assertOk();
});

it('shows the platform all three kinds and lets it approve them', function (): void {
    Sanctum::actingAs(storeOrderReader('platform-billing', [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::BILLING_PURCHASE_APPROVE,
    ]));

    $uuids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();

    expect($uuids)->toContain($this->storeOrder->uuid)
        ->and($uuids)->toContain($this->subscriptionOrder->uuid)
        ->and($uuids)->toContain($this->courseOrder->uuid);

    $this->postJson("/api/v1/orders/{$this->storeOrder->uuid}/approve")->assertOk();
});

it('never hides a buyer their own store purchase', function (): void {
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $uuids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();

    // The cut is about who ELSE may read it. Written on `kind` alone it takes
    // the payer's own receipt away from them.
    expect($uuids)->toContain($this->storeOrder->uuid);

    $this->getJson("/api/v1/orders/{$this->storeOrder->uuid}")->assertOk();
});
