<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CancelSubscription;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Actions\ReversePayment;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| Cancelling a subscription is four writes, and the money is the last of them.
|
| ⚠️ Before the transaction, a reversal that threw left the subscription
| `cancelled` and its courses `expired` with the payment still captured — and
| the retry died on its own claim («غير سارٍ أصلاً»). The case below makes the
| reversal fail once and asserts the END STATE after pressing again: access
| closed AND money returned, exactly as if the first press had worked.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'price_minor' => 30_000,
    ]);

    $this->buyer = User::factory()->create(['last_workspace_id' => null]);
    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
});

function atomicCancelSubscribed(): Subscription
{
    $order = app(PurchaseSubscription::class)->handle(test()->buyer, (string) test()->plan->uuid);
    app(ApproveOrder::class)->handle($order, test()->officer);

    return Subscription::query()->withoutWorkspaceScope()->firstOrFail();
}

it('leaves a retryable subscription when the reversal fails, and finishes on the retry', function (): void {
    $subscription = atomicCancelSubscribed();

    $this->app->instance(ReversePayment::class, new class extends ReversePayment
    {
        public function handle(PaymentTransaction $transaction, string $reason): PaymentTransaction
        {
            throw new RuntimeException('gateway unavailable');
        }
    });

    expect(fn () => app(CancelSubscription::class)->handle($subscription, 'طلب الطالب'))
        ->toThrow(RuntimeException::class, 'gateway unavailable');

    $enrollmentStatus = fn (): mixed => Enrollment::query()
        ->withoutWorkspaceScope()
        ->where('order_id', $subscription->order_id)
        ->value('status');

    $transactionStatus = fn (): PaymentStatus => PaymentTransaction::query()
        ->withoutWorkspaceScope()
        ->where('order_id', $subscription->order_id)
        ->firstOrFail()
        ->status;

    // Nothing of the failed press survives: still active, still open, still paid.
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($enrollmentStatus())->toBe('active')
        ->and($transactionStatus())->toBe(PaymentStatus::Captured);

    $this->app->forgetInstance(ReversePayment::class);

    app(CancelSubscription::class)->handle($subscription->fresh(), 'طلب الطالب');

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($enrollmentStatus())->toBe('expired')
        ->and($transactionStatus())->toBe(PaymentStatus::Reversed);
});
