<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Tests\Support\FakePaymentProvider;

/*
| FR-025أ — money that arrived with no order left to close.
|
| ⚠️ "دفع مرتين" WAS A 500, NOT A SURPLUS. `captured_order_id` is unique, and the
| second capture on a still-open order used to write it anyway — the payer's
| second payment crashed the webhook instead of becoming credits. Asking whether
| the order is already held is what turns the crash into the answer the spec
| gives.
|
| ⚠️ AND THE ENTRY MUST OPEN A LOT. The shipped placeholder posted `+1 refund`,
| which raised `remaining` with no lot behind it — only purchases and bonuses
| open one — so `sum(lots) = max(remaining, 0)` broke and the nightly
| reconciliation would have reported that balance for ever. The type is the fix,
| and it is also the honest label: this is money the student paid.
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

    app()->instance(FakePaymentProvider::class, $this->provider);
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->forgetInstance(PaymentProviderRegistry::class);

    $this->package = CreditPackage::query()->create([
        'name' => 'أربع حصص',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course);

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
        'credit_balance_id' => $this->balance->getKey(),
        'credit_package_id' => $this->package->getKey(),
        'course_id' => $this->course->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $this->order->getKey(),
        'credits' => 4,
        'teacher_rate_minor' => 5_000,
        'operating_fee_minor' => 500,
        'gateway_fee_minor' => 0,
        'total_minor' => 22_000,
        'currency' => 'QAR',
        'purchased_at' => now(),
    ]);
});

/** A second, independent payment against the same order — the payer paying twice. */
function secondPaymentOn(Order $order): PaymentTransaction
{
    $second = PaymentTransaction::create([
        'workspace_id' => $order->workspace_id,
        'order_id' => $order->getKey(),
        'provider' => 'gateway',
        'amount_minor' => $order->amount_minor,
        'currency' => $order->currency,
        'status' => PaymentStatus::Pending,
        'reference' => 'ref-second-payment',
    ]);

    return $second;
}

function callbackFor(PaymentTransaction $transaction, string $externalId): CallbackResult
{
    test()->provider->externalId = $externalId;

    test()->postJson('/api/v1/webhooks/payments/gateway', [
        'reference' => $transaction->reference,
        'amount_minor' => $transaction->amount_minor,
        'currency' => $transaction->currency,
    ])->assertAccepted();

    return ProviderCallback::query()
        ->withoutWorkspaceScope()
        ->where('external_id', $externalId)
        ->firstOrFail()
        ->result;
}

it('turns a second payment on one order into credits instead of a crash', function (): void {
    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $first = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();
    $second = secondPaymentOn($this->order);

    expect(callbackFor($first, 'evt_first'))->toBe(CallbackResult::Accepted);
    expect(callbackFor($second, 'evt_second'))->toBe(CallbackResult::Accepted);

    // Both captured — the money left the payer's account twice, and refusing the
    // second would be a charge with nothing behind it.
    expect(PaymentTransaction::query()->withoutWorkspaceScope()
        ->where('status', PaymentStatus::Captured->value)->count())->toBe(2);

    // One of them claims the order and one claims nothing. The unique index is
    // what makes that true; asking first is what keeps it from being a 500.
    expect(PaymentTransaction::query()->withoutWorkspaceScope()
        ->whereNotNull('captured_order_id')->count())->toBe(1);

    // Four credits for the order it closed, four more for the surplus.
    expect($this->balance->refresh()->remaining_credits)->toBe(8);
});

it('records the surplus as money paid, with a lot behind it', function (): void {
    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $first = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();
    callbackFor($first, 'evt_first');
    callbackFor(secondPaymentOn($this->order), 'evt_second');

    $surplus = CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('source_type', 'payment_surplus')
        ->firstOrFail();

    expect($surplus->type)->toBe(CreditTransactionType::Purchase)
        ->and($surplus->credits)->toBe(4)
        ->and($surplus->reason)->not->toBeNull();

    // The invariant ReconcileCreditBalancesJob checks every night: a positive
    // balance equals what its lots still hold. A surplus posted as a refund
    // would have broken it permanently and silently.
    $held = (int) CreditLot::query()
        ->withoutWorkspaceScope()
        ->where('credit_balance_id', $this->balance->getKey())
        ->sum('credits_remaining');

    expect($held)->toBe($this->balance->refresh()->remaining_credits);
});

it('credits a payment that landed after the order was cancelled', function (): void {
    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $transaction = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();

    $this->order->update(['status' => 'cancelled']);

    expect(callbackFor($transaction, 'evt_late'))->toBe(CallbackResult::Accepted);

    expect($transaction->refresh()->captured_order_id)->toBeNull()
        ->and($this->balance->refresh()->remaining_credits)->toBe(4);
});
