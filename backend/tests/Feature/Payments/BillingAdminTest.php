<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-016 · FR-017 · FR-021أ · FR-021ب — the catalogue and the platform's half of
| the price belong to the PLATFORM.
|
| The load-bearing assertion is the negative one: the workspace owner, the
| highest tenant role there is, must fail every write on both surfaces. A package
| a teacher can define is a sale price a teacher sets, and the operating fee and
| gateway cut are numbers FR-021ب forbids them even to see.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
});

function billingOperator(string $permission): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate('platform-'.md5($permission), 'web');
    $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));

    $operator = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $operator->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $operator);

    return $operator;
}

// Packages ------------------------------------------------------------------

it('lets the platform define a package with no price on it', function (): void {
    Sanctum::actingAs(billingOperator(Permissions::BILLING_PACKAGES_MANAGE));

    $payload = $this->postJson('/api/v1/admin/billing/packages', [
        'name' => 'ثماني حصص',
        'credits' => 8,
        'session_type' => ClassSessionType::Individual->value,
    ])->assertCreated()->json();

    expect($payload['credits'])->toBe(8)
        ->and($payload['is_active'])->toBeTrue();

    // FR-016 — the row has a size and a session type and NO price. What it costs
    // depends on which course it is bought against.
    foreach (array_keys($payload) as $key) {
        expect($key)->not->toContain('price')
            ->and($key)->not->toContain('minor')
            ->and($key)->not->toContain('rate');
    }

    // FR-017 — the size is a value, so the next one may differ without a deploy.
    $this->patchJson("/api/v1/admin/billing/packages/{$payload['uuid']}", ['credits' => 12])
        ->assertOk()
        ->assertJsonPath('credits', 12);
});

it('refuses the workspace owner every write on the catalogue', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/admin/billing/packages', [
        'name' => 'حزمتي',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual->value,
    ])->assertForbidden();

    expect(CreditPackage::query()->count())->toBe(0);
});

it('retires a package by disabling it, with no way to delete one', function (): void {
    Sanctum::actingAs(billingOperator(Permissions::BILLING_PACKAGES_MANAGE));

    $uuid = $this->postJson('/api/v1/admin/billing/packages', [
        'name' => 'قديمة',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual->value,
    ])->assertCreated()->json('uuid');

    $this->patchJson("/api/v1/admin/billing/packages/{$uuid}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('is_active', false);

    // FR-019 — the row survives retirement. Credits bought from it read their
    // validity through it and spec 015's books name it.
    $this->deleteJson("/api/v1/admin/billing/packages/{$uuid}")->assertStatus(405);

    expect(CreditPackage::query()->count())->toBe(1);
});

// Pricing -------------------------------------------------------------------

it('lets the platform set the operating fee and the gateway cut', function (): void {
    Sanctum::actingAs(billingOperator(Permissions::BILLING_PRICING_MANAGE));

    $this->putJson('/api/v1/admin/billing/pricing', [
        'operating_fee_minor' => ['individual' => 700, 'group' => 200],
        'gateway_fee_bps' => 250,
        'stop_selling_after_days' => 45,
    ])->assertOk()
        ->assertJsonPath('operating_fee_minor.individual', 700)
        ->assertJsonPath('operating_fee_minor.group', 200)
        ->assertJsonPath('gateway_fee_bps', 250)
        ->assertJsonPath('stop_selling_after_days', 45);

    $settings = app(BillingSettings::class);

    expect($settings->operatingFeeMinor(ClassSessionType::Group))->toBe(200)
        // A PUT carrying some keys leaves the rest alone, like the workspace's
        // own settings — the guard nobody sees until an alert stops firing.
        ->and($settings->maxUnredeemedCredits())->toBe(24);
});

it('refuses a gateway rate that would make the price unsolvable', function (): void {
    Sanctum::actingAs(billingOperator(Permissions::BILLING_PRICING_MANAGE));

    // 100% keeps the entire payment, and the gross-up has no solution. Refused
    // at the edge rather than left to throw inside CostPlusPricing on the first
    // purchase after the save.
    $this->putJson('/api/v1/admin/billing/pricing', ['gateway_fee_bps' => 10_000])
        ->assertStatus(422);
});

it('refuses the workspace owner both reading and writing the platform price', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    Sanctum::actingAs($this->owner);

    // FR-021ب forbids the teacher SEEING the sale price or any component of it,
    // so the read is 403 as much as the write is.
    $this->getJson('/api/v1/admin/billing/pricing')->assertForbidden();
    $this->putJson('/api/v1/admin/billing/pricing', ['gateway_fee_bps' => 0])->assertForbidden();
});
