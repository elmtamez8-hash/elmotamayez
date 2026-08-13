<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\ReconcilePayments;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\Roles;
use Tests\Support\FakePaymentProvider;

/*
| FR-016 — THE DIRECTION IS THE REQUIREMENT.
|
| A sweep that "makes our records match the provider's" sounds symmetric and is
| not:
|
|   · `pending → captured` is applied automatically. Nothing was granted on the
|     strength of a payment we had not seen, so applying it can only give someone
|     what they already paid for.
|
|   · `captured → failed` is NEVER applied. Credits are minted, an enrolment
|     exists, a session may already have been taught, and the ledger is
|     append-only — there is no shape for an undo. It is recorded and left to a
|     person.
|
| The status machine would refuse the second anyway. The finding is what makes
| that refusal VISIBLE instead of silent, which is the difference between a
| discrepancy someone deals with and one nobody hears about.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->provider = new FakePaymentProvider;
    $this->provider->identifier = 'gateway';
    $this->provider->sendsCallback = false;

    app()->instance(FakePaymentProvider::class, $this->provider);
    app()->tag([FakePaymentProvider::class], 'payment.providers');
    app()->forgetInstance(PaymentProviderRegistry::class);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'pending',
    ]);

    app(InitiatePayment::class)->handle($this->order, $this->provider);

    $this->transaction = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();
});

function providerReports(PaymentStatus $status, string $externalId = 'evt_dir'): void
{
    test()->provider->windowTransactions = [
        new CallbackEvent(
            provider: 'gateway',
            externalId: $externalId,
            reference: test()->transaction->reference,
            status: $status,
            amountMinor: test()->transaction->amount_minor,
            currency: test()->transaction->currency,
            failureReason: $status === PaymentStatus::Failed ? 'رفض المصرف العملية.' : null,
            safePayload: ['reference' => test()->transaction->reference],
        ),
    ];
}

it('applies a capture the provider settled without asking anyone', function (): void {
    providerReports(PaymentStatus::Captured);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    expect($this->transaction->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and($run->corrected_count)->toBe(1)
        ->and($run->unresolved_count)->toBe(0);
});

it('applies a failure on a pending payment, because nothing was granted', function (): void {
    providerReports(PaymentStatus::Failed);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    $this->transaction->refresh();

    // The same direction as the capture: pending is a state nothing depends on,
    // so settling it either way only tells the payer what happened.
    expect($this->transaction->status)->toBe(PaymentStatus::Failed)
        ->and($this->transaction->failure_reason)->not->toBeNull()
        ->and($run->corrected_count)->toBe(1);
});

it('refuses to fail a payment we have already captured, and says so', function (): void {
    // The money arrived and everything downstream of it has happened.
    PaymentTransaction::query()->withoutWorkspaceScope()->whereKey($this->transaction->getKey())->update([
        'status' => PaymentStatus::Captured->value,
        'captured_order_id' => $this->order->getKey(),
        'settled_at' => now(),
    ]);

    providerReports(PaymentStatus::Failed);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    expect($this->transaction->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and($run->corrected_count)->toBe(0)
        // Recorded, not applied — and a person is the only thing that can decide
        // what to do about it (a reversal is its own entry, never an edit).
        ->and($run->unresolved_count)->toBe(1)
        ->and($run->findings[0]['type'] ?? null)->toBe('provider_disagrees')
        ->and($run->findings[0]['local_status'] ?? null)->toBe('captured')
        ->and($run->findings[0]['provider_status'] ?? null)->toBe('failed');
});

it('reports no discrepancy when a settled payment simply agrees', function (): void {
    PaymentTransaction::query()->withoutWorkspaceScope()->whereKey($this->transaction->getKey())->update([
        'status' => PaymentStatus::Captured->value,
        'captured_order_id' => $this->order->getKey(),
    ]);

    providerReports(PaymentStatus::Captured);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    // A provider re-reporting what we already know is the commonest row in every
    // window. Counted as checked, and nothing else.
    expect($run->checked_count)->toBe(1)
        ->and($run->corrected_count)->toBe(0)
        ->and($run->unresolved_count)->toBe(0);
});
