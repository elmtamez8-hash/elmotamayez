<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-021 · FR-002 — PINNING THE PATH THE PRODUCT ACTUALLY COLLECTS ON.
|
| Q-2 is explicit that the manual path stays beside the gateway rather than being
| replaced: some payers will always wire, and a gateway outage must not stop
| collection. So the whole wire → receipt → approval → credits chain is asserted
| end to end, with no provider in it anywhere.
|
| ⚠️ NO BARE `Queue::fake()`. The mint hangs off a queued listener, and a blanket
| fake would turn "the credits arrived" into a confident assertion about an empty
| table.
|
| It also carries the positive half of SC-006 and the edge case that says an
| operating-fee change must not reprice a purchase already made.
*/

beforeEach(function (): void {
    Storage::fake('local');

    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $this->package = CreditPackage::query()->create([
        'name' => 'أربع حصص',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);
});

/** Someone the platform trusts to approve a sale it made. */
function platformApprover(): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate('platform-billing', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_PURCHASE_APPROVE, 'web'));

    $approver = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $approver->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $approver);

    return $approver;
}

it('collects a whole purchase with no payment provider anywhere in it', function (): void {
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $this->postJson('/api/v1/billing/purchases', [
        'course' => $this->course->uuid,
        'package' => $this->package->uuid,
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->firstOrFail();

    // Not one credit yet (FR-018): intent is not payment.
    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(0);

    $this->postJson("/api/v1/orders/{$order->uuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('transfer.png'),
        'method' => 'bank_transfer',
    ])->assertOk();

    expect($order->refresh()->status)->toBe('under_review');

    Sanctum::actingAs(platformApprover());

    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertOk();

    expect($order->refresh()->status)->toBe('approved')
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(4)
        // The transaction records HOW, which is the half of FR-018 an operator
        // reconciling a bank statement is actually reading for.
        ->and($order->transactions()->withoutWorkspaceScope()->first()?->method?->value)
        ->toBe('bank_transfer');
});

it('adds no credits for any receipt nobody approved', function (): void {
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $this->postJson('/api/v1/billing/purchases', [
        'course' => $this->course->uuid,
        'package' => $this->package->uuid,
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->firstOrFail();

    $this->postJson("/api/v1/orders/{$order->uuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('transfer.png'),
    ])->assertOk();

    // SC-006 — uploading is not paying. The file being on disk and the status
    // reading "under review" is exactly the state a student would most like to
    // be treated as payment.
    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(0);
});

it('does not reprice a purchase when the operating fee moves under it', function (): void {
    Sanctum::actingAs($this->student);
    $this->setCurrentWorkspace($this->workspace, $this->student);

    $this->postJson('/api/v1/billing/purchases', [
        'course' => $this->course->uuid,
        'package' => $this->package->uuid,
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->firstOrFail();

    // The platform triples its fee between the wire and the approval.
    PlatformSettings::set('billing.operating_fee_minor.individual', 1_500);

    $this->postJson("/api/v1/orders/{$order->uuid}/receipt", [
        'receipt' => UploadedFile::fake()->image('transfer.png'),
    ])->assertOk();

    Sanctum::actingAs(platformApprover());
    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertOk();

    $purchase = CreditPurchase::query()->withoutWorkspaceScope()->firstOrFail();

    // ⚠️ THE GUARANTEE IS THE FOUR-PART SNAPSHOT, not a promise in a docblock.
    // The student wired 22,000 against the price they were shown; a price
    // recomputed at approval would either short them credits or hand the
    // platform a shortfall nobody decided on.
    expect($purchase->total_minor)->toBe(22_000)
        ->and($purchase->operating_fee_minor)->toBe(2_000)
        ->and($order->refresh()->amount_minor)->toBe(22_000)
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(4);
});
