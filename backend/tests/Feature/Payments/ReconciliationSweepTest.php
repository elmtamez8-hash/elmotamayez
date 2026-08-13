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
| SC-004 — the payment that succeeded and never told us.
|
| ⚠️ THIS IS THE ONLY WAY INTO US2, AND THE FAKE'S "SUCCEEDS WITHOUT CALLING
| BACK" MODE EXISTS FOR IT ALONE. A provider that always notifies can never
| produce the case the whole story is about; without that mode the sweep would be
| tested against a state it never has to repair.
|
| ⚠️ AND THE SWEEP REPLAYS THE CALLBACK RATHER THAN CAPTURING BY HAND, which is
| what makes "reconciled" and "notified" the same event with different postmarks
| — the credits, the enrolment and the surplus branch all come out of one path.
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
    // The mode this whole file exists for: the money moves, nothing calls back.
    $this->provider->sendsCallback = false;

    app()->instance(FakePaymentProvider::class, $this->provider);
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->forgetInstance(PaymentProviderRegistry::class);

    $this->package = CreditPackage::query()->create([
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
        'credit_package_id' => $this->package->getKey(),
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
});

/** What the provider reports about a transaction when asked for its window. */
function settledAtProvider(PaymentTransaction $transaction, PaymentStatus $status, string $externalId): CallbackEvent
{
    return new CallbackEvent(
        provider: 'gateway',
        externalId: $externalId,
        reference: $transaction->reference,
        status: $status,
        amountMinor: $transaction->amount_minor,
        currency: $transaction->currency,
        safePayload: ['reference' => $transaction->reference],
    );
}

it('leaves the student unpaid until the sweep runs', function (): void {
    // The state SC-004 is about, asserted before the repair so the repair means
    // something: the money left their account and the platform knows nothing.
    expect($this->transaction->status)->toBe(PaymentStatus::Pending)
        ->and(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('captures a payment the provider settled and never announced', function (): void {
    $this->provider->windowTransactions = [
        settledAtProvider($this->transaction, PaymentStatus::Captured, 'evt_swept'),
    ];

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    expect($this->transaction->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and($this->transaction->captured_order_id)->toBe((int) $this->order->getKey())
        // The whole chain behind a capture, reached through the one path: the
        // credits are minted because the sweep delivered the callback rather
        // than writing the status itself.
        ->and(billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits)
        ->toBe(4);

    expect($run->checked_count)->toBe(1)
        ->and($run->corrected_count)->toBe(1)
        ->and($run->unresolved_count)->toBe(0);
});

it('writes down the window it covered, not only when it ran', function (): void {
    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    expect($run->window_from->lessThan($run->window_to))->toBeTrue();

    $second = app(ReconcilePayments::class)->handle([$this->provider]);

    // ⚠️ The next window starts exactly where the last one stopped. A start
    // derived from the clock would skip whatever elapsed between a late run and
    // the one before it — and nothing downstream would ever notice.
    expect($second->window_from->toIso8601String())->toBe($run->window_to->toIso8601String());
});

it('records a provider transaction it has no reference for, and invents nothing', function (): void {
    $this->provider->windowTransactions = [
        new CallbackEvent(
            provider: 'gateway',
            externalId: 'evt_stranger',
            reference: 'REF-WE-NEVER-ISSUED',
            status: PaymentStatus::Captured,
            amountMinor: 22_000,
            currency: 'QAR',
        ),
    ];

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    // An order, a workspace and a payer would all have to be guessed, and a
    // guess in this table is money attributed to the wrong person.
    expect($run->unresolved_count)->toBe(1)
        ->and($run->findings[0]['type'] ?? null)->toBe('unknown_reference')
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('says nothing was found rather than nothing was looked at', function (): void {
    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    expect(PaymentReconciliationRun::query()->count())->toBe(1)
        ->and($run->checked_count)->toBe(0)
        ->and($run->unresolved_count)->toBe(0)
        // A run row exists even on a quiet hour, because "the sweep found
        // nothing" and "the sweep has not run since Tuesday" are otherwise the
        // same reassuring empty screen.
        ->and($run->ran_at)->not->toBeNull();
});
