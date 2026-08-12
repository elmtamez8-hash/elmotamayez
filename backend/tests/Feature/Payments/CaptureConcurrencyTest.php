<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\HandleProviderCallback;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Enums\CallbackResult;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\ProviderCallback;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Database\QueryException;
use Tests\Support\FakePaymentProvider;

/*
| The webhook racing the reconciliation sweep on ONE row.
|
| ⚠️ THE GUARD BEING TESTED IS THE ONE THE DOCUMENTS SAY IS NOT THE GUARANTEE.
| The unique index on (provider, external_id) is a fast path; what makes a
| concurrent capture safe is the conditional UPDATE — `WHERE status = 'pending'`
| — and the loser of it skipping the whole effect. Two callers, one mint.
|
| It walks the same path real concurrency does: two Action calls against one row,
| in sequence, with no lock between them. And never `lockForUpdate()`, which is a
| no-op on SQLite and would make this test prove nothing about MySQL.
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

it('lets exactly one of two racing captures through', function (): void {
    $event = new CallbackEvent(
        provider: 'gateway',
        externalId: 'evt_race',
        reference: $this->transaction->reference,
        status: PaymentStatus::Captured,
        amountMinor: 22_000,
        currency: 'QAR',
    );

    // Two callbacks, each with its own row — as a resend with a fresh event id
    // produces, and as the sweep and a webhook do when they meet.
    $first = ProviderCallback::factory()->create(['provider' => 'gateway', 'external_id' => 'evt_race']);
    $second = ProviderCallback::factory()->create(['provider' => 'gateway', 'external_id' => 'evt_race_2']);

    $handle = app(HandleProviderCallback::class);

    // ⚠️ BOTH ARE HANDED THE SAME MODEL STATE the first one read. That is what
    // concurrency looks like from inside: the second caller's in-memory row says
    // `pending` because it loaded before the first committed.
    $resultA = $handle->handle($first, $event);
    $resultB = $handle->handle($second, $event);

    expect($resultA)->toBe(CallbackResult::Accepted)
        // Not an error and not a second capture — the loser records that it was
        // a duplicate and stops.
        ->and($resultB)->toBe(CallbackResult::Duplicate);

    expect(PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->where('status', PaymentStatus::Captured->value)
        ->count())->toBe(1);
});

it('cannot record two captures against one order at the database level', function (): void {
    // The invariant underneath the whole design, asserted where it actually
    // lives — the engine. A second row claiming the same order is refused
    // whatever the application layer believes.
    PaymentTransaction::query()->withoutWorkspaceScope()->whereKey($this->transaction->getKey())->update([
        'status' => PaymentStatus::Captured->value,
        'captured_order_id' => $this->order->getKey(),
    ]);

    expect(fn () => PaymentTransaction::create([
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $this->order->getKey(),
        'provider' => 'gateway',
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'second-capture',
    ])->forceFill(['captured_order_id' => $this->order->getKey()])->save())
        ->toThrow(QueryException::class);
});
