<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Tenancy\Support\Roles;

/*
| US3 · SC-006 — two operators reaching one order.
|
| ⚠️ THE TWO ORDER MODELS ARE HYDRATED SEPARATELY, AND THAT IS THE TEST. A
| decision written through `$order->update()` changes the instance in memory too,
| so a second call on the SAME instance sees `approved` and refuses — the test
| passes on the first run and proves nothing about concurrency at all. Two
| independent reads are what the second operator's request actually holds: a row
| loaded before the first one committed.
|
| And there is no compensating path behind either decision. An approval enrols a
| student and mints credits; a rejection closes the order. Whichever lands second
| must land on nothing.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'under_review',
    ]);
});

/** The same row, read twice — the shape two concurrent requests actually hold. */
function twoReadsOf(Order $order): array
{
    return [
        Order::query()->withoutWorkspaceScope()->findOrFail($order->getKey()),
        Order::query()->withoutWorkspaceScope()->findOrFail($order->getKey()),
    ];
}

it('lets exactly one of two concurrent approvals through', function (): void {
    [$first, $second] = twoReadsOf($this->order);

    $approve = app(ApproveOrder::class);

    $approve->handle($first, $this->owner);

    expect(fn () => $approve->handle($second, $this->owner))
        ->toThrow(DomainException::class);

    // One captured transaction, not two — and the same number the unique index
    // on `captured_order_id` would have allowed anyway. The conditional UPDATE
    // is what stops the second attempt before it reaches the index; the index is
    // what makes that true under a race this test cannot stage.
    expect(PaymentTransaction::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail()->status)
        ->toBe(PaymentStatus::Captured)
        ->and($this->order->refresh()->status)->toBe('approved');
});

it('claims the order for the capture, which the manual path never did', function (): void {
    app(ApproveOrder::class)->handle($this->order, $this->owner);

    $transaction = PaymentTransaction::query()->withoutWorkspaceScope()->firstOrFail();

    // The manual path used to leave this NULL, which left the one-capture-per-
    // order invariant covering only the gateway — on a product where the manual
    // path is the one in use.
    expect($transaction->captured_order_id)->toBe((int) $this->order->getKey());
});

it('lets an approval and a rejection meet without both landing', function (): void {
    [$first, $second] = twoReadsOf($this->order);

    app(ApproveOrder::class)->handle($first, $this->owner);

    expect(fn () => app(RejectOrder::class)->handle($second, $this->owner, 'الإيصال غير مطابق'))
        ->toThrow(DomainException::class);

    expect($this->order->refresh()->status)->toBe('approved')
        ->and($this->order->rejection_reason)->toBeNull();
});

it('leaves a rejected order with no transaction and no credits', function (): void {
    [$first, $second] = twoReadsOf($this->order);

    app(RejectOrder::class)->handle($first, $this->owner, 'الإيصال غير مطابق');

    expect(fn () => app(ApproveOrder::class)->handle($second, $this->owner))
        ->toThrow(DomainException::class);

    // SC-006's negative half: a decision that lost the race must leave nothing
    // behind it. There is no compensating path — no credit is ever un-minted —
    // so "written and then reversed" is not an acceptable outcome here.
    expect(PaymentTransaction::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and($this->order->refresh()->status)->toBe('rejected');
});
