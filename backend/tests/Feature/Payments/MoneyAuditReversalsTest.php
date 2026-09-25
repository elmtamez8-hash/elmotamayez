<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CancelSubscription;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Actions\ReverseCourseOrder;
use App\Modules\Payments\Actions\ReverseCreditOrder;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Listeners\ActivateSubscription;
use App\Modules\Payments\Listeners\CreateEnrollmentFromOrder;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Payments\Support\SubscriptionDays;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Events\CourseAccessWithdrawn;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
| The money audit of 2026-09-25: six ways a reversal left money and access
| disagreeing. Each case fails against the build before its fix.
|
| ⚠️ THE OFFICER OWNS ANOTHER WORKSPACE (`last_workspace_id` stamped) — the
| fixture line that exposed all five layers of the 024 defect. A conditional
| UPDATE spoken under the officer's own workspace would match zero rows here.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$decoy] = $this->createWorkspaceWithOwner(['name' => 'ورشة الموظّف']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الفيزياء']);

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->course->forceFill(['status' => 'published', 'price_minor' => 4999, 'is_sequential' => false])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
    ]);

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $decoy->getKey()])->save();

    app()->forgetInstance(WorkspaceContext::class);
});

function moneyAuditMonth(): Subscription
{
    $order = app(PurchaseSubscription::class)->handle(test()->student, (string) test()->plan->uuid);
    app(ApproveOrder::class)->handle($order, test()->officer);

    return Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->firstOrFail();
}

function moneyAuditEnrollment(): Enrollment
{
    return Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', test()->student->getKey())
        ->where('course_id', test()->course->getKey())
        ->sole();
}

function moneyAuditOrder(int $id): Order
{
    return Order::query()->withoutWorkspaceScope()->findOrFail($id);
}

function moneyAuditBalance(int $id): CreditBalance
{
    return CreditBalance::query()->withoutWorkspaceScope()->findOrFail($id);
}

/** Spend credits the way a delivered seat does: a Consume drawn from the lots. */
function moneyAuditConsume(CreditBalance $balance, int $credits, int $sourceId): void
{
    app(CreditLedger::class)->post(new CreditMovement(
        balance: $balance,
        type: CreditTransactionType::Consume,
        credits: -$credits,
        sourceType: 'session_booking',
        sourceId: $sourceId,
    ));
}

/*
| #1 — a credit package had no way back: `ReverseCourseOrder` refused it and
| `AdjustCredits` has no screen. The panel reverses it now, and takes back what
| THIS purchase still holds — the two spent credits stay spent.
*/
it('reverses a credit package from the panel and claws back only its unconsumed credits', function (): void {
    PlatformSettings::set('billing.operating_fee_minor.individual', 500);
    PlatformSettings::set('billing.gateway_fee_bps', 0);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', 0);

    $package = CreditPackage::query()->create([
        'name' => 'عشر حصص',
        'credits' => 10,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    // Credits are bought on a course the student is a party to.
    app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn () => app(EnrollStudent::class)->handle($this->course, $this->student, 'manual'),
    );

    $purchase = app(PurchaseCredits::class)->handle($this->student, $this->course, $package);
    $order = moneyAuditOrder((int) $purchase->order_id);
    app(ApproveOrder::class)->handle($order, $this->officer);

    $balance = moneyAuditBalance((int) $purchase->credit_balance_id);
    expect($balance->remaining_credits)->toBe(10);

    moneyAuditConsume($balance, 2, 1);

    expect(app(ReverseCreditOrder::class)->refundableFor($order))->toBe(8);

    $this->actingAs($this->officer);
    app()->forgetInstance(WorkspaceContext::class);

    Livewire::test(ListOrders::class)
        ->callTableAction('reverse_credits', $order->getKey(), ['reason' => 'استرداد بنكي'])
        ->assertHasNoTableActionErrors();

    expect(moneyAuditOrder((int) $order->getKey())->status)->toBe('cancelled')
        ->and(PaymentTransaction::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->sole()->status)
        ->toBe(PaymentStatus::Reversed)
        // 10 bought − 2 spent − 8 taken back: the spent two stay spent.
        ->and(moneyAuditBalance((int) $balance->getKey())->remaining_credits)->toBe(0)
        ->and((int) CreditLot::query()->withoutWorkspaceScope()->where('credit_balance_id', $balance->getKey())->sum('credits_remaining'))->toBe(0)
        ->and(CreditTransaction::query()->withoutWorkspaceScope()
            ->where('source_type', ReverseCreditOrder::CREDIT_SOURCE_TYPE)
            ->where('source_id', $order->getKey())
            ->sole()->credits)->toBe(-8);

    // A second press loses the claim and writes nothing.
    expect(fn () => app(ReverseCreditOrder::class)->handle(moneyAuditOrder((int) $order->getKey()), $this->officer, 'مرة ثانية'))
        ->toThrow(DomainException::class);
});

it('reverses an hours plan, which writes no subscription row, the same way', function (): void {
    $plan = Plan::factory()->bySessions(12)->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $order = app(PurchaseSubscription::class)->handle($this->student, (string) $plan->uuid);
    app(ApproveOrder::class)->handle($order, $this->officer);

    $entry = CreditTransaction::query()->withoutWorkspaceScope()
        ->where('source_type', ActivateSubscription::CREDIT_SOURCE_TYPE)
        ->where('source_id', $order->getKey())
        ->sole();
    $balance = moneyAuditBalance((int) $entry->credit_balance_id);

    moneyAuditConsume($balance, 3, 2);

    app(ReverseCreditOrder::class)->handle(moneyAuditOrder((int) $order->getKey()), $this->officer, 'استرداد');

    expect(moneyAuditOrder((int) $order->getKey())->status)->toBe('cancelled')
        ->and(moneyAuditBalance((int) $balance->getKey())->remaining_credits)->toBe(0);
});

/*
| #2 — a subscriber who bought the course outright had the ONE enrolment row
| moved to the purchase (`EnrollStudent::handOver()`); reversing the purchase
| cancelled it and the month they are still paying for went dark.
*/
it('hands the course back to the running subscription when an outright purchase over it is reversed', function (): void {
    Event::fake([CourseAccessWithdrawn::class]);

    $month = moneyAuditMonth();

    $order = app(CreateOrder::class)->handle(
        Course::query()->withoutWorkspaceScope()->findOrFail($this->course->getKey()),
        $this->student,
    );
    app(ApproveOrder::class)->handle($order, $this->officer);

    // The precondition: the purchase took the subscriber's row.
    expect(moneyAuditEnrollment()->order_id)->toBe((int) $order->getKey());

    app(ReverseCourseOrder::class)->handle(moneyAuditOrder((int) $order->getKey()), $this->officer, 'استرداد');

    $enrollment = moneyAuditEnrollment();

    expect($enrollment->status)->toBe('active')
        ->and($enrollment->source)->toBe('subscription')
        ->and($enrollment->order_id)->toBe((int) $month->order_id)
        ->and($enrollment->expires_at?->toDateString())
        ->toBe(app(SubscriptionDays::class)->endOf(CarbonImmutable::parse($month->effective_ends_on))->toDateString());

    Event::assertNotDispatched(CourseAccessWithdrawn::class);
});

/*
| #3 + #5 — OWNER DECISION 2026-09-25: cancelling the RUNNING month after its
| renewal was approved starts the renewal TODAY, same length. Before, the
| renewal stayed dated from the end of the cancelled month (a gap in which every
| seat was charged to credits), and the cancelled order still read «معتمَد».
*/
it('starts the renewal today when the running month is cancelled, and cancels its order', function (): void {
    $running = moneyAuditMonth();
    $renewal = moneyAuditMonth();

    $today = app(SubscriptionDays::class)->today();
    expect(CarbonImmutable::parse($renewal->starts_on)->greaterThan($today))->toBeTrue();

    app(CancelSubscription::class)->handle($running, 'استرداد الشهر الجاري');

    $renewal->refresh();

    expect($renewal->starts_on->toDateString())->toBe($today->toDateString())
        ->and($renewal->ends_on->toDateString())->toBe($today->addDays(29)->toDateString())
        ->and(moneyAuditEnrollment()->status)->toBe('active')
        ->and(moneyAuditEnrollment()->order_id)->toBe((int) $renewal->order_id)
        ->and(moneyAuditEnrollment()->expires_at?->toDateString())
        ->toBe(app(SubscriptionDays::class)->endOf(CarbonImmutable::parse($renewal->effective_ends_on))->toDateString())
        // #5: the reversed order no longer reads approved.
        ->and(moneyAuditOrder((int) $running->order_id)->status)->toBe('cancelled')
        ->and(Subscription::query()->withoutWorkspaceScope()->liveOn(now())->whereKey($renewal->getKey())->exists())->toBeTrue();
});

/*
| #5 — `CancelSubscription` reversed the payment and left the order reading
| «معتمَد», while `ReverseCourseOrder` writes `cancelled`. One word for one act.
*/
it('cancels the order of a cancelled subscription', function (): void {
    $only = moneyAuditMonth();

    app(CancelSubscription::class)->handle($only, 'استرداد');

    expect(moneyAuditOrder((int) $only->order_id)->status)->toBe('cancelled');
});

/*
| #4 — the listeners run after commit on a worker, carrying the order as it was
| at approval. A reversal landing in that gap cancelled an order with nothing
| yet to close, and the listener then opened access on it for ever.
*/
it('opens nothing when the order was reversed before the queued listener ran', function (): void {
    Event::fake([PaymentApproved::class]);

    $courseOrder = app(CreateOrder::class)->handle(
        Course::query()->withoutWorkspaceScope()->findOrFail($this->course->getKey()),
        $this->student,
    );
    app(ApproveOrder::class)->handle($courseOrder, $this->officer);

    $monthOrder = app(PurchaseSubscription::class)->handle($this->student, (string) $this->plan->uuid);
    app(ApproveOrder::class)->handle($monthOrder, $this->officer);

    // The events, as the worker will receive them: approved in memory.
    $staleCourse = moneyAuditOrder((int) $courseOrder->getKey());
    $staleMonth = moneyAuditOrder((int) $monthOrder->getKey());

    // The reversal lands first.
    Order::query()->withoutWorkspaceScope()
        ->whereKey([$courseOrder->getKey(), $monthOrder->getKey()])
        ->update(['status' => 'cancelled']);

    app(CreateEnrollmentFromOrder::class)->handle(new PaymentApproved($staleCourse));
    app(ActivateSubscription::class)->handle(new PaymentApproved($staleMonth));

    expect(Enrollment::query()->withoutWorkspaceScope()->where('student_user_id', $this->student->getKey())->count())->toBe(0)
        ->and(Subscription::query()->withoutWorkspaceScope()->where('order_id', $monthOrder->getKey())->exists())->toBeFalse();
});
