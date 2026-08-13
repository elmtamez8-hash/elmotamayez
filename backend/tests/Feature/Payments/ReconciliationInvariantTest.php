<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\ReconcilePayments;
use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Tests\Support\FakePaymentProvider;

/*
| THE THIRD CHECK — the one that sees the failure the other two cannot.
|
| ⚠️ COMPARING OUR STATUS AGAINST THE PROVIDER'S IS A COMPARISON BETWEEN TWO
| PARTIES WHO AGREE. The charge succeeded, we wrote `captured`, and the queued
| listener that mints the credits died in between. The provider says settled and
| so do we — perfect agreement, zero findings every night for ever, and a student
| who paid is still blocked. Nothing in the first two checks can ever see it.
|
| The invariant that does comes from OUTSIDE both parties: a captured credit
| order must carry a ledger entry. Same lesson as `sessionsAgainstEntries()` in
| the 006 sweep, which exists for the identical reason and is named in CLAUDE.md.
|
| ⚠️ AND IT IS TWO GROUPED READS PER CHUNK, NEVER A QUERY PER ORDER. This is a
| join over the two tables that grow with every sale, running every hour.
*/

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);

    $this->provider = new FakePaymentProvider;
    $this->provider->identifier = 'gateway';
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
});

/** A credit order whose money arrived — with or without the mint behind it. */
function capturedCreditOrder(bool $minted): Order
{
    $test = test();

    $order = Order::create([
        'workspace_id' => $test->workspace->getKey(),
        'user_id' => $test->student->getKey(),
        'course_id' => $test->course->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'approved',
    ]);

    $balance = billingBalance($test->workspace, $test->student, $test->course);

    CreditPurchase::create([
        'credit_balance_id' => $balance->getKey(),
        'credit_package_id' => $test->package->getKey(),
        'course_id' => $test->course->getKey(),
        'workspace_id' => $test->workspace->getKey(),
        'order_id' => $order->getKey(),
        'credits' => 4,
        'teacher_rate_minor' => 5_000,
        'operating_fee_minor' => 2_000,
        'gateway_fee_minor' => 0,
        'total_minor' => 22_000,
        'currency' => 'QAR',
        'purchased_at' => now(),
    ]);

    $transaction = PaymentTransaction::create([
        'workspace_id' => $test->workspace->getKey(),
        'order_id' => $order->getKey(),
        'provider' => 'gateway',
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'REF-'.$order->getKey(),
    ]);

    $transaction->forceFill(['captured_order_id' => $order->getKey()])->save();

    if ($minted) {
        // The production path, not a fixture that writes a similar-looking row:
        // the check keys on `source_type = credit_purchase` and the purchase id,
        // and a helper posting `test_grant` would satisfy a human reading the
        // test while satisfying nothing the invariant asks.
        app(RecordCreditPurchase::class)->handle($order->refresh());
    }

    return $order;
}

it('finds a captured credit order whose ledger entry never landed', function (): void {
    $orphan = capturedCreditOrder(minted: false);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    // The provider agrees with us completely; this is the only reason anyone
    // ever hears about it.
    expect($run->unresolved_count)->toBe(1)
        ->and($run->findings[0]['type'] ?? null)->toBe('captured_without_credits')
        ->and($run->findings[0]['order_uuid'] ?? null)->toBe($orphan->uuid);
});

it('says nothing about a capture whose credits were minted', function (): void {
    capturedCreditOrder(minted: true);

    $run = app(ReconcilePayments::class)->handle([$this->provider]);

    expect($run->unresolved_count)->toBe(0)
        ->and($run->findings)->toBe([]);
});

it('ignores a course order, which mints no credits by design', function (): void {
    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'approved',
    ]);

    $transaction = PaymentTransaction::create([
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $order->getKey(),
        'provider' => 'gateway',
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'REF-COURSE',
    ]);

    $transaction->forceFill(['captured_order_id' => $order->getKey()])->save();

    // A course purchase buys an enrolment, not credits. Flagging it would make
    // every ordinary sale a finding, and a report that cries wolf on every row
    // is a report nobody opens.
    expect(app(ReconcilePayments::class)->handle([$this->provider])->unresolved_count)->toBe(0);
});

it('does not flag a payment still on its way', function (): void {
    Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'gateway',
        'status' => 'pending',
    ]);

    // Nothing was captured, so nothing is owed. The check keys on
    // `captured_order_id`, which is only written by a capture — an order status
    // would have been the wrong key, since "approved" is set by a human before
    // any money is recorded.
    expect(app(ReconcilePayments::class)->handle([$this->provider])->unresolved_count)->toBe(0);
});
