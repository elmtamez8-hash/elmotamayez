<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Jobs\ClaimSubscriptionSeatsJob;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Actions\ReconcilePayments;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakePaymentProvider;

/*
| ٠٣٦ — AN HOURS SALE WAS INVISIBLE TO BOTH AUDITORS, AND EACH WENT QUIET RATHER
| THAN RED.
|
| ⛔ (1) THE CHAIN BEHIND ONE PAYMENT ANSWERED «nothing was bought». A plan sold
| by the hour mints straight from the ORDER and writes no `credit_purchases` row
| at all; the auditor's walk started at that row, so `$purchase` was null and
| with it the entry, the lot and every allocation beneath. A `200` carrying four
| nulls about a sale that settled is worse than an error — it reads as evidence
| that nothing happened.
|
| ⛔ (2) THE NIGHTLY INVARIANT COULD NOT SEE THE FAILURE IT EXISTS FOR. «A
| captured order must carry a ledger entry» is the ONLY check that catches money
| taken and nothing minted — the provider and we both say «paid», so the other
| two checks agree perfectly for ever. It was filtered to `credits` orders and
| joined through `credit_purchases`, i.e. blind to a whole product.
|
| ⚠️ AND BOTH CASES ARE BUILT BY THE PRODUCTION PATH, never by a fixture writing
| a similar-looking ledger row: the whole defect was that the auditors ask for a
| key the writer does not use, so a fixture typing the key by hand measures the
| test's own agreement with itself.
*/
beforeEach(function (): void {
    // ⚠️ ONLY the seat-claim job — a bare `Queue::fake()` swallows
    // `ActivateSubscription` itself, which is the thing whose output is audited.
    Queue::fake([ClaimSubscriptionSeatsJob::class]);

    $this->seed(RolesAndPermissionsSeeder::class);

    // ⚠️ THE OFFICER OWNS A DIFFERENT WORKSPACE — the one fixture line that
    // exposed all five layers of the ٠٢٤ defect on this exact approval path.
    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published', 'title' => 'الفيزياء'])->save();

    $this->cohort = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'created_by' => $this->teacher->getKey(),
            'name' => 'مجموعة السبت',
        ]),
    );

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();

    $this->provider = new FakePaymentProvider;
    $this->provider->identifier = 'gateway';
    $this->provider->sendsCallback = false;

    app()->instance(FakePaymentProvider::class, $this->provider);
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->forgetInstance(PaymentProviderRegistry::class);
});

/** An hours plan of this teacher, bought and approved — the real path. */
function hoursSaleOrder(): Order
{
    $plan = Plan::factory()->bySessions(12)->group()->create([
        'workspace_id' => test()->workspace->getKey(),
        'coverage_type' => PlanCoverage::Cohort,
        'coverage_uuid' => test()->cohort->uuid,
    ]);

    $order = app(PurchaseSubscription::class)->handle(
        test()->student,
        (string) $plan->uuid,
        'cohort',
        (string) test()->cohort->uuid,
    );

    app(ApproveOrder::class)->handle($order->refresh(), test()->officer);

    return $order->refresh();
}

/**
 * The money row the auditor opens, captured against this order.
 *
 * ⚠️ FOUND FIRST, NEVER BLINDLY CREATED. `captured_order_id` is a UNIQUE column
 * — one capture per order, the guard ٠٢٧ wrote — and the approval path already
 * writes one, so a fixture that inserts a second collides on it.
 */
function hoursSalePayment(Order $order): PaymentTransaction
{
    $existing = PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->where('captured_order_id', $order->getKey())
        ->first();

    if ($existing instanceof PaymentTransaction) {
        return $existing;
    }

    $payment = PaymentTransaction::create([
        'workspace_id' => $order->workspace_id,
        'order_id' => $order->getKey(),
        'provider' => 'gateway',
        'amount_minor' => $order->amount_minor,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'REF-HOURS-'.$order->getKey(),
    ]);

    $payment->forceFill(['captured_order_id' => $order->getKey()])->save();

    return $payment;
}

/** A reader who holds the platform's audit permission and nothing else here. */
function hoursSaleAuditor(): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate('hours-sale-auditor', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_AUDIT_VIEW, 'web'));

    $auditor = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $auditor->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $auditor);

    return $auditor;
}

it('shows the auditor the credits an hours sale actually minted', function (): void {
    $order = hoursSaleOrder();
    $payment = hoursSalePayment($order);

    Sanctum::actingAs(hoursSaleAuditor());

    $response = $this->getJson('/api/v1/admin/payments/audit/'.$payment->uuid)->assertOk();

    /*
    | ⛔ THE NUMBER, NOT MERELY «not null». Twelve is what was bought, and a
    | chain that resolved some other entry would still be non-null.
    */
    $response->assertJsonPath('data.credits_purchased', 12)
        ->assertJsonPath('data.order_uuid', $order->uuid)
        // ⚠️ AND THE LOT BENEATH IT: the entry is what the fix looks up, and the
        // two steps under it hang off the entry's key — so an entry found and a
        // lot still null would mean the chain stops one row later.
        ->assertJsonPath('data.credits_remaining_in_lot', 12);
});

it('reports an hours order whose money was captured and whose credits never landed', function (): void {
    /*
    | ⚠️ THE MINT IS ABSENT BECAUSE IT NEVER RAN, not because a row was deleted:
    | the order is written with the snapshot a purchase carries and the queued
    | listener is simply never dispatched — which is the production failure this
    | invariant exists for, a worker dying between the capture and the mint.
    */
    $bought = hoursSaleOrder();

    $orphan = Order::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => $bought->kind,
        'amount_minor' => $bought->amount_minor,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'approved',
        'metadata' => $bought->metadata,
    ]);

    hoursSalePayment($orphan);
    hoursSalePayment($bought);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    $reported = array_column($run->findings, 'order_uuid');

    /*
    | ⛔ AND THE SALE THAT DID MINT IS NAMED AS ABSENT. «The orphan is reported»
    | is equally true of a sweep that reports every hours order ever sold, which
    | would be a nightly report nobody reads within a week.
    */
    expect($reported)->toContain($orphan->uuid)
        ->and($reported)->not->toContain($bought->uuid);

    expect(array_column($run->findings, 'type'))->toContain('captured_without_credits');
});
