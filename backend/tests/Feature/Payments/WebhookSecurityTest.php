<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaymentProvider;

/*
| US1 · SC-002 · SC-003 · NFR-011 — the four things that must be true of a route
| anyone on the internet may POST to.
|
| ⚠️ NO BARE Queue::fake(). The processing job is queued; a bare fake would make
| every assertion here a statement about a table nothing ever wrote to.
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

function callback(string $reference, int $amountMinor, string $provider = 'gateway'): TestResponse
{
    return test()->postJson("/api/v1/webhooks/payments/{$provider}", [
        'reference' => $reference,
        'amount_minor' => $amountMinor,
        'currency' => 'QAR',
    ]);
}

// Signature ------------------------------------------------------------------

it('refuses a forged signature, records it, and says nothing about it', function (): void {
    $this->provider->signatureValid = false;

    // ⚠️ 202, exactly as for a valid one. A distinguishable response is an
    // oracle that tells an attacker when they are getting close.
    callback($this->transaction->reference, 22_000)->assertAccepted();

    $callback = ProviderCallback::query()->withoutWorkspaceScope()->firstOrFail();

    expect($callback->signature_valid)->toBeFalse()
        ->and($callback->result)->toBe(CallbackResult::RejectedSignature)
        // The body was never parsed, so there is no event id to record — and
        // reading one from the body would let the attacker choose the
        // deduplication key.
        ->and($callback->external_id)->toBeNull();

    // Zero effect on the money.
    expect($this->transaction->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('records two refusals as two rows, not one', function (): void {
    $this->provider->signatureValid = false;

    callback($this->transaction->reference, 22_000);
    callback($this->transaction->reference, 22_000);

    // ⚠️ NULL does not collide with NULL in a unique index — which is exactly
    // why the refused row's external_id is null rather than a constant. A
    // constant would make one junk request turn every later refusal into a
    // "duplicate" and silence the log SC-002 requires.
    expect(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(2);
});

// Idempotency ----------------------------------------------------------------

it('applies ten identical callbacks exactly once', function (): void {
    for ($i = 0; $i < 10; $i++) {
        callback($this->transaction->reference, 22_000)->assertAccepted();
    }

    $this->transaction->refresh();

    expect($this->transaction->status)->toBe(PaymentStatus::Captured)
        ->and(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('still applies once when the gateway mints a new event id per resend', function (): void {
    // ⚠️ THE CASE THAT PROVES WHERE THE GUARANTEE ACTUALLY LIVES. With a fresh
    // external_id each time, the unique index on (provider, external_id)
    // prevents nothing — ten rows, ten jobs. What holds is the effect-level
    // guard: one captured transaction per order, claimed by a conditional
    // UPDATE, and every later attempt reads a final status and stops.
    $this->provider->freshExternalIdPerResend = true;

    for ($i = 0; $i < 10; $i++) {
        callback($this->transaction->reference, 22_000)->assertAccepted();
    }

    $captured = PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->where('status', PaymentStatus::Captured->value)
        ->count();

    expect(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(10)
        ->and($captured)->toBe(1)
        ->and(ProviderCallback::query()->withoutWorkspaceScope()
            ->where('result', CallbackResult::Duplicate->value)->count())->toBe(9);
});

// Amount ---------------------------------------------------------------------

it('refuses an amount the signature vouched for but our record disagrees with', function (): void {
    // Perfectly signed — and for a different amount. The signature proves WHO
    // sent it, never that the message matches our own row.
    callback($this->transaction->reference, 19_000)->assertAccepted();

    $this->transaction->refresh();

    expect($this->transaction->status)->toBe(PaymentStatus::Mismatch)
        ->and($this->transaction->captured_order_id)->toBeNull();

    expect(ProviderCallback::query()->withoutWorkspaceScope()->firstOrFail()->result)
        ->toBe(CallbackResult::Mismatch);
});

// Provider resolution --------------------------------------------------------

it('answers 404 for a provider nobody registered', function (): void {
    callback($this->transaction->reference, 22_000, 'paymob')->assertNotFound();

    expect(ProviderCallback::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses every callback addressed to the manual provider', function (): void {
    // A live, registered provider that sends no callbacks — so anything in its
    // name is an impersonation, and its verifySignature() answers false always.
    callback($this->transaction->reference, 22_000, 'manual')->assertAccepted();

    $callback = ProviderCallback::query()->withoutWorkspaceScope()->firstOrFail();

    expect($callback->result)->toBe(CallbackResult::RejectedSignature)
        ->and($this->transaction->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('will not let one provider claim another provider transaction', function (): void {
    $second = new FakePaymentProvider;
    $second->identifier = 'other-gateway';

    app()->instance(FakePaymentProvider::class, $second);
    app()->forgetInstance(PaymentProviderRegistry::class);
    app()->tag([FakePaymentProvider::class], 'payment.providers');

    // Correctly signed by `other-gateway`, naming a reference that belongs to
    // `gateway`. The lookup is on the PAIR, so it finds nothing.
    callback($this->transaction->reference, 22_000, 'other-gateway')->assertAccepted();

    expect($this->transaction->fresh()->status)->toBe(PaymentStatus::Pending);

    $callback = ProviderCallback::query()->withoutWorkspaceScope()
        ->where('provider', 'other-gateway')->firstOrFail();

    expect($callback->result)->toBe(CallbackResult::Deferred);
});
