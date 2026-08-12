<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePaymentProvider;

/*
| US1 · SC-001 — the student pays and the platform knows within seconds, with no
| human in the path.
|
| ⚠️ NO BARE Queue::fake() ANYWHERE IN THIS FILE. The callback job is queued, so a
| fake with no arguments swallows it and every assertion below becomes a
| confident statement about an empty table — green, and proving the opposite of
| what it claims. The queue runs on `sync` here, which is the whole point.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
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
});

function chargeOrder(Order $order, FakePaymentProvider $provider): PaymentTransaction
{
    app(InitiatePayment::class)->handle($order, $provider);

    return PaymentTransaction::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();
}

function postCallback(PaymentTransaction $transaction, FakePaymentProvider $provider, ?int $amountMinor = null): TestResponse
{
    return test()->postJson(
        '/api/v1/webhooks/payments/'.$provider->identifier,
        json_decode($provider->callbackBody(
            $transaction->reference,
            $amountMinor ?? $transaction->amount_minor,
        ), true),
    );
}

// ---------------------------------------------------------------------------

it('records the attempt before the provider is ever asked', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/payments/{$this->order->uuid}/charge")
        ->assertCreated()
        ->assertJsonPath('currency', 'QAR')
        ->assertJsonPath('amount_minor', 22_000)
        // Never a payment instrument, a token, or anything shaped like one.
        ->assertJsonMissingPath('payload');

    $transaction = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();

    expect($transaction->status)->toBe(PaymentStatus::Pending)
        ->and($transaction->amount_minor)->toBe(22_000)
        // NULL until captured — the lock is claimed by the capture, not held
        // from the start.
        ->and($transaction->captured_order_id)->toBeNull();
});

it('captures, mints and notifies from one signed callback', function (): void {
    // ⚠️ Asserted BEFORE the outcome: a notification with no approved template
    // is logged and dropped, so without this the assertion below would pass
    // vacuously against zero rows for the wrong reason.
    expect(MessageTemplate::query()->where('type', NotificationType::PaymentConfirmed->value)->exists())
        ->toBeTrue();

    $transaction = chargeOrder($this->order, $this->provider);

    postCallback($transaction, $this->provider)->assertAccepted();

    $transaction->refresh();

    expect($transaction->status)->toBe(PaymentStatus::Captured)
        // Written by the SAME statement that moved the status — never a second
        // call, which would leave a window between them.
        ->and($transaction->captured_order_id)->toBe($this->order->getKey())
        ->and($transaction->settled_at)->not->toBeNull();

    expect(Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::PaymentConfirmed->value)
        ->exists())->toBeTrue();
});

it('stores the callback with its tenant resolved from the order, never from the body', function (): void {
    $transaction = chargeOrder($this->order, $this->provider);

    postCallback($transaction, $this->provider);

    $callback = ProviderCallback::query()->withoutWorkspaceScope()->firstOrFail();

    expect($callback->signature_valid)->toBeTrue()
        ->and($callback->workspace_id)->toBe($this->workspace->getKey())
        ->and($callback->payment_transaction_id)->toBe($transaction->getKey())
        ->and($callback->processed_at)->not->toBeNull();
});

it('answers 202 and defers when the callback outruns our own write', function (): void {
    // No transaction exists for this reference at all — the declared edge case.
    $this->postJson('/api/v1/webhooks/payments/gateway', [
        'reference' => 'FAKE-does-not-exist',
        'amount_minor' => 22_000,
        'currency' => 'QAR',
    ])->assertAccepted();

    $callback = ProviderCallback::query()->withoutWorkspaceScope()->firstOrFail();

    // Deferred rows stay unprocessed on purpose — `processed_at` is what the
    // retry queue reads.
    expect($callback->result?->value)->toBe('deferred')
        ->and($callback->processed_at)->toBeNull()
        ->and($callback->workspace_id)->toBeNull();
});

it('settles the oldest order first when a payer has several', function (): void {
    $older = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'amount_minor' => 10_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'pending',
    ]);

    // Written after the insert: `created_at` is not fillable, so passing it to
    // create() is silently ignored and the "older" order would be the newer one.
    $older->forceFill(['created_at' => now()->subWeek()])->save();

    // ⚠️ The newest is passed FIRST, so an implementation returning "whatever
    // the collection happened to hold" would pass while meaning nothing.
    $chosen = app(InitiatePayment::class)->allocate(new Collection([$this->order, $older]));

    expect($chosen?->getKey())->toBe($older->getKey());
});

it('refuses to let anyone but the payer start or read a payment', function (): void {
    $transaction = chargeOrder($this->order, $this->provider);

    // The teacher of this very workspace — who passes BelongsToWorkspace, the
    // only automatic filter there is.
    Sanctum::actingAs($this->teacher);

    $this->postJson("/api/v1/payments/{$this->order->uuid}/charge")->assertForbidden();
    $this->getJson("/api/v1/payments/{$transaction->uuid}")->assertForbidden();

    Sanctum::actingAs($this->student);

    $this->getJson("/api/v1/payments/{$transaction->uuid}")
        ->assertOk()
        ->assertJsonPath('amount_minor', 22_000)
        ->assertJsonMissingPath('payload');
});
