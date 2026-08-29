<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/*
| Buying a subscription, and the guard that stops one payment buying two (T092).
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'price_minor' => 30_000,
    ]);

    /*
    | ⚠️ THE BUYER IS BUILT WITHOUT A SEEDER AND WITHOUT `addWorkspaceMember`,
    | AND `last_workspace_id` IS LEFT NULL. That column is what
    | `WorkspaceContext::id()` falls back to, and NOTHING on a student's path
    | writes it in production — so a fixture that stamps it gives the test student
    | a context real students never have, and every scope-related assertion below
    | measures a person who does not exist.
    */
    $this->buyer = User::factory()->create(['last_workspace_id' => null]);

    // Approving a subscription is a PLATFORM decision (`OrderKind::Subscription`
    // requires it): the plan is the platform's own, so the person who would press
    // the button is the person being paid.
    $this->approver = makePlatformStaff(Roles::FINANCE_ADMIN);
});

it('writes an order and NOT a subscription, because the money has not arrived', function (): void {
    $order = app(PurchaseSubscription::class)->handle($this->buyer, (string) $this->plan->uuid);

    expect($order->kind)->toBe(OrderKind::Subscription)
        ->and((int) $order->amount_minor)->toBe(30_000)
        ->and($order->metadata['plan_uuid'] ?? null)->toBe($this->plan->uuid)
        // A month of access handed out against a transfer that may never clear.
        ->and(Subscription::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a plan the platform has not priced', function (): void {
    $unpriced = Plan::factory()->unpriced()->create(['workspace_id' => $this->workspace->getKey()]);

    // ⚠️ NOT «free». `(int) null === 0`, so a catalogue filtering on `is_active`
    // alone would sell a month for nothing and activate it on approval.
    expect(fn () => app(PurchaseSubscription::class)->handle($this->buyer, (string) $unpriced->uuid))
        ->toThrow(DomainException::class);
});

it('activates on approval, opens the course, and dates it from the plan', function (): void {
    $order = app(PurchaseSubscription::class)->handle($this->buyer, (string) $this->plan->uuid);

    app(ApproveOrder::class)->handle($order, $this->approver);

    $subscription = Subscription::query()->withoutWorkspaceScope()->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and((int) $subscription->student_user_id)->toBe((int) $this->buyer->getKey())
        ->and($subscription->ends_on->toDateString())
        ->toBe(CarbonImmutable::today()->addDays(30)->toDateString())
        ->and($subscription->effective_ends_on->toDateString())->toBe($subscription->ends_on->toDateString());

    // ⚠️ THE ENROLMENT IS WHAT ACTUALLY OPENS ANYTHING — `expires_at` gates
    // nothing in this tree, so an `active` row is the access and the source is
    // how the expiry sweep finds it again.
    $enrollment = Enrollment::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->buyer->getKey())
        ->firstOrFail();

    expect($enrollment->source)->toBe('subscription')
        ->and((int) $enrollment->order_id)->toBe((int) $order->getKey())
        ->and($enrollment->status)->toBe('active');
});

it('snapshots the price from the ORDER, not from the plan as it stands today', function (): void {
    /*
    | FR-030. A manual transfer takes days to clear and a platform officer may
    | legitimately reprice inside that lag — reading the plan at activation would
    | charge this student a number they were never shown, days after they paid.
    */
    $order = app(PurchaseSubscription::class)->handle($this->buyer, (string) $this->plan->uuid);

    $this->plan->forceFill(['price_minor' => 99_000])->save();

    app(ApproveOrder::class)->handle($order, $this->approver);

    expect((int) Subscription::query()->withoutWorkspaceScope()->value('price_minor'))->toBe(30_000);
});

it('writes ONE subscription however many times the event is redelivered', function (): void {
    $order = app(PurchaseSubscription::class)->handle($this->buyer, (string) $this->plan->uuid);

    app(ApproveOrder::class)->handle($order, $this->approver);

    // A redelivery, a retried job, an operator replaying a payment. Without
    // `unique(order_id)` this is a second active subscription for one payment:
    // access doubled in length, invisible, and the ledger perfectly balanced.
    app(ActivateSubscription::class)->handle(new PaymentApproved($order->refresh()));
    app(ActivateSubscription::class)->handle(new PaymentApproved($order->refresh()));

    expect(Subscription::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('is the DATABASE that refuses the second one', function (): void {
    // ⚠️ THE MECHANISM, NOT THE OUTCOME. The test above passes just as well
    // against a read-then-write `->exists()` check, which is the race itself
    // rather than a guard against it.
    $order = app(PurchaseSubscription::class)->handle($this->buyer, (string) $this->plan->uuid);
    app(ApproveOrder::class)->handle($order, $this->approver);

    expect(fn () => Subscription::factory()->create([
        'plan_id' => $this->plan->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => $this->buyer->getKey(),
        'order_id' => $order->getKey(),
    ]))->toThrow(QueryException::class);
});

it('activates through a gateway capture as well as a manual approval', function (): void {
    /*
    | ⚠️ TWO DOORS ON ONE PAYMENT. Binding one leaves every subscription bought
    | through the other paid for and never activated — the defect
    | `CreateEnrollmentFromOrder` threw a TypeError over on the first real gateway
    | payment.
    */
    $order = app(PurchaseSubscription::class)->handle($this->buyer, (string) $this->plan->uuid);

    $transaction = PaymentTransaction::create([
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => $order->getKey(),
        'provider' => 'stripe',
        'amount_minor' => $order->amount_minor,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'REF-SUB-CAPTURE',
    ]);

    app(ActivateSubscription::class)->handle(new PaymentCaptured($order, $transaction));

    expect(Subscription::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('ignores every order kind but a subscription', function (): void {
    // A listener with no `kind` filter turns the cheapest notebook in the store
    // into a month of free lessons.
    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->buyer->getKey(),
        'kind' => OrderKind::Store,
        'amount_minor' => 500,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
        'metadata' => ['plan_uuid' => $this->plan->uuid],
    ]);

    app(ActivateSubscription::class)->handle(new PaymentApproved($order));

    expect(Subscription::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('covers only the named course when the plan is scoped to one', function (): void {
    $second = courseWithRate((int) $this->workspace->getKey());
    $second->forceFill(['status' => 'published'])->save();

    $scoped = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $order = app(PurchaseSubscription::class)->handle($this->buyer, (string) $scoped->uuid);
    app(ApproveOrder::class)->handle($order, $this->approver);

    $courseIds = Enrollment::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->buyer->getKey())
        ->pluck('course_id')
        ->all();

    expect($courseIds)->toBe([(int) $this->course->getKey()]);
});
