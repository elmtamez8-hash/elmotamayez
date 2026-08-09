<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\CreditsPurchased;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use Illuminate\Support\Facades\Event;

/*
| The MVP's headline path: an approved credit order becomes credits, once.
|
| The Action is exercised directly rather than through the listener, because the
| listener is one `if` and a call — and the properties worth asserting (idempotent
| on redelivery, a lot opened, the event fired after the write) all live in the
| Action.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
    $this->balance = billingBalance($this->workspace, $this->student);

    $this->package = CreditPackage::factory()->create(['credits' => 8]);

    $this->order = Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'kind' => OrderKind::Credits,
        'amount' => 400.00,
        'currency' => 'QAR',
        'status' => 'approved',
        'approved_by' => $this->owner->getKey(),
    ]);

    $this->purchase = CreditPurchase::query()->create([
        'credit_balance_id' => $this->balance->getKey(),
        'credit_package_id' => $this->package->getKey(),
        'course_id' => $this->balance->course_id,
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $this->order->getKey(),
        'credits' => 8,
        'teacher_rate_minor' => 4000,
        'operating_fee_minor' => 500,
        'gateway_fee_minor' => 0,
        'total_minor' => 36000,
        'currency' => 'QAR',
        'purchased_at' => now(),
    ]);
});

it('turns an approved credit order into credits and a lot', function (): void {
    app(RecordCreditPurchase::class)->handle($this->order);

    $balance = $this->balance->refresh();

    expect($balance->remaining_credits)->toBe(8)
        ->and($balance->purchased_credits)->toBe(8)
        ->and($balance->consumed_credits)->toBe(0)
        ->and(CreditLot::query()->withoutWorkspaceScope()->sum('credits_remaining'))->toBe(8);
});

it('writes nothing the second time the same approval arrives', function (): void {
    $first = app(RecordCreditPurchase::class)->handle($this->order);
    $second = app(RecordCreditPurchase::class)->handle($this->order);

    // Redelivery is normal on a queue. Two entries here would be free credits,
    // and the invariant would still hold — the ledger would simply say the
    // student bought sixteen.
    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and($this->balance->refresh()->remaining_credits)->toBe(8);
});

it('ignores an order that is not a credit purchase', function (): void {
    $this->order->forceFill(['kind' => OrderKind::Course])->save();

    expect(app(RecordCreditPurchase::class)->handle($this->order))->toBeNull()
        ->and($this->balance->refresh()->remaining_credits)->toBe(0);
});

it('refuses an approved credit order with no purchase behind it', function (): void {
    $orphan = Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'kind' => OrderKind::Credits,
        'amount' => 100.00,
        'status' => 'approved',
    ]);

    // Not an empty case: the price snapshot is what spec 015's books are built
    // from, and minting credits without one puts an unpriced entry in the ledger
    // for ever.
    expect(fn () => app(RecordCreditPurchase::class)->handle($orphan))
        ->toThrow(RuntimeException::class);
});

it('announces the purchase after the write, not during it', function (): void {
    Event::fake([CreditsPurchased::class]);

    app(RecordCreditPurchase::class)->handle($this->order);

    Event::assertDispatched(
        CreditsPurchased::class,
        fn (CreditsPurchased $event): bool => $event->transaction->credits === 8
            && $event->purchase->is($this->purchase),
    );
});

it('never expires a lot bought from a package with no validity', function (): void {
    app(RecordCreditPurchase::class)->handle($this->order);

    // Null is the launch policy (Q-5): the structure is built and the policy is
    // off, so that turning expiry on later is not a migration over credits people
    // bought on the understanding they were permanent.
    expect(CreditLot::query()->withoutWorkspaceScope()->firstOrFail()->expires_at)->toBeNull();
});
