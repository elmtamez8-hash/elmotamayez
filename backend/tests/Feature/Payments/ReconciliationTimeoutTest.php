<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\ReconcilePayments;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Tests\Support\FakePaymentProvider;

/*
| FR-015 · FR-017 — what happens to the ones nobody ever answered for.
|
| ⚠️ A PENDING ROW IS NOT NEUTRAL. It is what an operator reads when they ask
| whether a student paid, and an abandoned browser tab is the commonest outcome
| of a redirect flow rather than an edge case. Left for ever, the collection
| report becomes a list of maybes.
|
| ⚠️ AND AN ABANDONED CALLBACK USED TO BE WRITTEN AND READ BY NOBODY. FR-017 asks
| for a declared retry limit AND for what it gives up on to be visible; the first
| half shipped in phase 3 and the second is this line. A notification that
| exhausted twelve attempts is the single loudest signal available that something
| is wrong with a provider, and it was sitting in a column no screen selected.
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

/** Push a transaction back in time, past the declared timeout. */
function agePayment(PaymentTransaction $transaction, int $minutes): void
{
    PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->whereKey($transaction->getKey())
        ->update(['created_at' => now()->subMinutes($minutes)]);
}

it('closes a payment nobody answered for with a final status', function (): void {
    config(['payments.pending_timeout_minutes' => 120]);

    agePayment($this->transaction, 180);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    $this->transaction->refresh();

    expect($this->transaction->status)->toBe(PaymentStatus::Expired)
        ->and($this->transaction->status->isFinal())->toBeTrue()
        // A sentence, not a status. "Expired" tells the payer nothing they can
        // act on; this is what FR-008 asks for.
        ->and($this->transaction->failure_reason)->not->toBeNull()
        ->and($run->corrected_count)->toBe(1);
});

it('leaves a payment inside its window alone', function (): void {
    config(['payments.pending_timeout_minutes' => 120]);

    agePayment($this->transaction, 30);

    app(ReconcilePayments::class)->handle([$this->provider]);

    // Half an hour into a redirect flow is a person reading a bank page, not an
    // abandoned payment. Expiring it would refuse money that is on its way.
    expect($this->transaction->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('settles a stale payment by its outcome rather than expiring it', function (): void {
    config(['payments.pending_timeout_minutes' => 120]);

    agePayment($this->transaction, 180);

    $this->provider->windowTransactions = [
        new CallbackEvent(
            provider: 'gateway',
            externalId: 'evt_late_but_real',
            reference: $this->transaction->reference,
            status: PaymentStatus::Captured,
            amountMinor: 9_000,
            currency: 'QAR',
            safePayload: ['reference' => $this->transaction->reference],
        ),
    ];

    app(ReconcilePayments::class)->handle([$this->provider]);

    // ⚠️ ORDER INSIDE THE SWEEP, ASSERTED. The provider pass runs BEFORE the
    // expiry pass — reversed, a payment the provider had just confirmed would be
    // expired out from under its own capture, and the payer would be refused
    // money that had already left their account.
    expect($this->transaction->refresh()->status)->toBe(PaymentStatus::Captured);
});

it('counts a notification that exhausted its retries as unresolved', function (): void {
    ProviderCallback::factory()->create([
        'provider' => 'gateway',
        'external_id' => 'evt_given_up',
        'result' => CallbackResult::Abandoned,
        'processed_at' => now()->subMinutes(5),
    ]);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    // The loudest signal a provider integration produces, and it was reaching
    // nobody: twelve attempts spent on one notification means either our side
    // never wrote the transaction or theirs is naming something that does not
    // exist. Either way a person has to look.
    expect($run->unresolved_count)->toBe(1);
});

it('does not re-count the same abandoned notification every hour', function (): void {
    ProviderCallback::factory()->create([
        'provider' => 'gateway',
        'external_id' => 'evt_given_up',
        'result' => CallbackResult::Abandoned,
        'processed_at' => CarbonImmutable::now()->subDays(2),
    ]);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    // Scoped to the window by WHEN IT WAS GIVEN UP ON. An all-time count would
    // rise for ever and never fall, and a number that only grows is a number
    // people stop reading.
    expect($run->unresolved_count)->toBe(0);
});
