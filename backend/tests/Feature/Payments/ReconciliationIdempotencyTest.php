<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\ReconcilePayments;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentReconciliationRun;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Tests\Support\FakePaymentProvider;

/*
| SC-005 — the sweep run twice changes nothing twice.
|
| Two shapes, and they fail differently:
|
|   · CONSECUTIVE runs — the second starts where the first stopped, so it should
|     see an empty window. A start derived from the clock would re-scan the same
|     hour every time and depend entirely on the effect being idempotent.
|
|   · OVERLAPPING runs — two workers that both read "the last run" before either
|     wrote one, so both scan the SAME window. This is what an un-expired lock,
|     a manual dispatch beside the schedule, or a retry after a timeout actually
|     produces, and it is staged here by deleting the run row between passes.
|
| ⚠️ AND THE GUARANTEE IS NOT THE WINDOW ARITHMETIC. It is the unique index on
| (provider, external_id) collapsing both passes into one callback row, and the
| conditional UPDATE that the second capture loses. The window only decides how
| much work is wasted.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $this->provider = new FakePaymentProvider;
    $this->provider->identifier = 'gateway';
    $this->provider->sendsCallback = false;

    app()->instance(FakePaymentProvider::class, $this->provider);
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->forgetInstance(PaymentProviderRegistry::class);

    $package = CreditPackage::query()->create([
        'name' => 'أربع حصص',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'pending',
    ]);

    CreditPurchase::create([
        'credit_balance_id' => billingBalance($this->workspace, $this->student, $this->course)->getKey(),
        'credit_package_id' => $package->getKey(),
        'course_id' => $this->course->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $this->order->getKey(),
        'credits' => 4,
        'teacher_rate_minor' => 5_000,
        'operating_fee_minor' => 2_000,
        'gateway_fee_minor' => 0,
        'total_minor' => 22_000,
        'currency' => 'QAR',
        'purchased_at' => now(),
    ]);

    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $this->transaction = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();

    $this->provider->windowTransactions = [
        new CallbackEvent(
            provider: 'gateway',
            externalId: 'evt_sweep',
            reference: $this->transaction->reference,
            status: PaymentStatus::Captured,
            amountMinor: 22_000,
            currency: 'QAR',
            safePayload: ['reference' => $this->transaction->reference],
        ),
    ];
});

it('corrects a payment once across two consecutive runs', function (): void {
    $first = app(ReconcilePayments::class)->handle([$this->provider]);
    $second = app(ReconcilePayments::class)->handle([$this->provider]);

    expect($first->corrected_count)->toBe(1)
        // The second run's window opens where the first closed, so the provider
        // is asked about a stretch of time that has barely begun.
        ->and($second->corrected_count)->toBe(0);

    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(4);
});

it('corrects it once even when two runs scan the same window', function (): void {
    app(ReconcilePayments::class)->handle([$this->provider]);

    // The overlap, staged: the second worker read "the last run" before the
    // first one had written it, so it scans the identical window.
    PaymentReconciliationRun::query()->delete();

    $overlapping = app(ReconcilePayments::class)->handle([$this->provider]);

    expect($overlapping->checked_count)->toBe(1)
        // It looked at the same payment and had nothing left to do — because the
        // callback row already exists, not because the window said so.
        ->and($overlapping->corrected_count)->toBe(0);

    expect(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()
            ->where('status', PaymentStatus::Captured->value)->count())->toBe(1)
        ->and(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('collapses a sweep and a late webhook carrying the same event id', function (): void {
    app(ReconcilePayments::class)->handle([$this->provider]);

    // The notification finally arrives, hours late, carrying the id the sweep
    // already recorded on its behalf — which is the point: the sweep replays the
    // provider's own event, so the two are the same notification and the unique
    // index knows it. One row, one capture, one mint.
    $this->provider->externalId = 'evt_sweep';

    $this->postJson('/api/v1/webhooks/payments/gateway', [
        'reference' => $this->transaction->reference,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
    ])->assertAccepted();

    expect(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(4);
});
