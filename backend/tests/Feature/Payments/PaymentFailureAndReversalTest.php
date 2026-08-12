<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\ReversePayment;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentProvider;

/*
| US1 — the two paths nobody exercises by hand: the payment that fails, and the
| payment that succeeded and was then taken back.
|
| ⚠️ The failed path had no test at all in the first plan — one of the four
| states NFR-011 names, and the one a student is most likely to meet.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->provider = new FakePaymentProvider;
    $this->provider->identifier = 'gateway';

    app()->instance(FakePaymentProvider::class, $this->provider);
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->forgetInstance(PaymentProviderRegistry::class);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'pending',
    ]);

    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $this->transaction = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();
});

function notify(string $reference, int $amountMinor = 22_000): TestResponse
{
    return test()->postJson('/api/v1/webhooks/payments/gateway', [
        'reference' => $reference,
        'amount_minor' => $amountMinor,
        'currency' => 'QAR',
    ]);
}

// Failure --------------------------------------------------------------------

it('leaves the debt standing and tells the student why', function (): void {
    $this->provider->outcome = PaymentStatus::Failed;
    $this->provider->failureReason = 'رفض البنك العملية.';

    notify($this->transaction->reference)->assertAccepted();

    $this->transaction->refresh();

    expect($this->transaction->status)->toBe(PaymentStatus::Failed)
        // FR-008 — a sentence the student can act on, stored on the row.
        ->and($this->transaction->failure_reason)->toBe('رفض البنك العملية.')
        // The order is untouched: nothing was paid, so nothing is settled.
        ->and($this->order->fresh()->status)->toBe('pending')
        ->and($this->transaction->captured_order_id)->toBeNull();

    expect(Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::PaymentFailed->value)
        ->exists())->toBeTrue();
});

it('never walks a failure forward to captured on its own', function (): void {
    $this->provider->outcome = PaymentStatus::Failed;
    notify($this->transaction->reference);

    // The provider changes its mind. FR-014 forbids an automatic correction in
    // the direction that harms the student — and this one would ALSO charge
    // them, so it needs a human, not a retry.
    $this->provider->outcome = PaymentStatus::Captured;
    $this->provider->externalId = 'evt_2';
    notify($this->transaction->reference)->assertAccepted();

    expect($this->transaction->fresh()->status)->toBe(PaymentStatus::Failed);
});

// Reversal -------------------------------------------------------------------

it('reverses a capture without editing it, and lets the student pay again', function (): void {
    notify($this->transaction->reference)->assertAccepted();

    $this->transaction->refresh();
    expect($this->transaction->status)->toBe(PaymentStatus::Captured)
        ->and($this->transaction->captured_order_id)->toBe($this->order->getKey());

    app(ReversePayment::class)->handle($this->transaction, 'نزاع بنكي من حامل البطاقة.');

    $this->transaction->refresh();

    expect($this->transaction->status)->toBe(PaymentStatus::Reversed)
        ->and($this->transaction->failure_reason)->toBe('نزاع بنكي من حامل البطاقة.')
        // ⚠️ THE LINE THAT MATTERS. Without the clear, the unique index still
        // holds this order's claim and the student can NEVER pay for it again —
        // silently, permanently.
        ->and($this->transaction->captured_order_id)->toBeNull();

    // Proven, not asserted in the abstract: a second attempt is accepted.
    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $second = PaymentTransaction::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    // A new event at the provider, so a new event id — the first one is already
    // recorded, and re-sending it would correctly be read as a duplicate.
    $this->provider->externalId = 'evt_2';

    notify($second->reference)->assertAccepted();

    expect($second->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($second->fresh()->captured_order_id)->toBe($this->order->getKey());
});

it('tells the payer their payment was taken back', function (): void {
    notify($this->transaction->reference);

    app(ReversePayment::class)->handle($this->transaction->fresh(), 'استرجاع من البنك.');

    expect(Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::PaymentReversed->value)
        ->exists())->toBeTrue();
});

it('refuses to reverse anything that was never captured', function (): void {
    expect(fn () => app(ReversePayment::class)->handle($this->transaction, 'محاولة'))
        ->toThrow(DomainException::class);
});

// The order that stopped standing ---------------------------------------------

it('credits a payment that lands after its order was cancelled, and never refuses it', function (): void {
    $this->order->update(['status' => 'cancelled']);

    notify($this->transaction->reference)->assertAccepted();

    $this->transaction->refresh();

    // ⚠️ CAPTURED, not refused. The money left the student's account; refusing
    // the transaction would be a debit with nothing behind it. And no order is
    // closed — there is no longer an order to close.
    expect($this->transaction->status)->toBe(PaymentStatus::Captured)
        ->and($this->transaction->captured_order_id)->toBeNull()
        ->and($this->order->fresh()->status)->toBe('cancelled');
});
