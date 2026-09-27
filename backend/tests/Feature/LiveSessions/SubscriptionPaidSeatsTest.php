<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\UpdateClassSession;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Actions\CancelSubscription;
use App\Modules\Payments\Actions\PlaceCreditHold;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\CreditAccounts;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| The seats a subscription was PAYING FOR, when it ends early (audit 2026-09-27).
|
| ⚠️ WHAT THIS MEASURED BEFORE THE FIX. A student who bought the course outright
| AND subscribed to its group kept the outright enrolment when the subscription
| was cancelled — rightly — so `SubscriptionAccess::close()` closed nothing,
| announced nothing, and every future seat the subscription had claimed stayed
| booked with no credit hold behind it. Each one was then charged −1 at delivery
| with the floor off: a debt per lesson and the course withheld.
| `SubscriptionSeatLifecycleTest` said this case was untested; it is this file.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = User::factory()->create(['last_workspace_id' => null]);

    // Bought outright, open-ended: the enrolment the cancellation must NOT close.
    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'order_id' => null,
        'source' => 'purchase',
        'status' => EnrollmentStatus::Active,
        'enrolled_at' => now(),
    ]);

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'session_type' => ClassSessionType::Group,
        'coverage_type' => PlanCoverage::Workspace,
    ]);

    $this->subscription = Subscription::factory()->create([
        'plan_id' => $this->plan->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => SubscriptionStatus::Active,
        'starts_on' => CarbonImmutable::today()->subDays(5),
        'ends_on' => CarbonImmutable::today()->addDays(24),
        'effective_ends_on' => CarbonImmutable::today()->addDays(24),
    ]);
});

function paidSeat(CarbonImmutable $startsAt, ClassSessionType $type = ClassSessionType::Group): SessionBooking
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace(
        $test->workspace,
        function () use ($test, $startsAt, $type): SessionBooking {
            $session = ClassSession::factory()->create([
                'workspace_id' => $test->workspace->getKey(),
                'teacher_profile_id' => $test->course->teacher_profile_id,
                'course_id' => $test->course->getKey(),
                'type' => $type,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
                'seats_total' => 8,
                'seats_taken' => 1,
            ]);

            return SessionBooking::query()->create([
                'workspace_id' => $test->workspace->getKey(),
                'class_session_id' => $session->getKey(),
                'student_user_id' => $test->student->getKey(),
                'status' => BookingStatus::Booked,
                'is_billable' => true,
                'booked_at' => now(),
            ]);
        },
    );
}

function holdCreditFor(SessionBooking $seat): void
{
    $test = test();
    $balance = app(CreditAccounts::class)->balanceFor($test->student, $test->course);
    // Funded: a prepaid balance refuses a hold at zero, which is not the question.
    $balance->forceFill(['remaining_credits' => 5, 'purchased_credits' => 5])->save();

    expect(app(PlaceCreditHold::class)->handle($balance, (int) $seat->class_session_id))->toBeTrue();
}

it('releases the seats the cancelled subscription paid for, though the course was also bought outright', function (): void {
    $first = paidSeat(CarbonImmutable::now()->addDays(3));
    $second = paidSeat(CarbonImmutable::now()->addDays(10));

    app(CancelSubscription::class)->handle($this->subscription, 'طلب الطالب');

    expect($first->refresh()->status)->toBe(BookingStatus::Released)
        ->and($first->is_billable)->toBeFalse()
        ->and($second->refresh()->status)->toBe(BookingStatus::Released)
        // The outright purchase is untouched: access somebody paid for once.
        ->and(Enrollment::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())
            ->where('course_id', $this->course->getKey())
            ->value('status'))->toBe(EnrollmentStatus::Active->value);
});

it('keeps a seat a credit hold pays for', function (): void {
    $held = paidSeat(CarbonImmutable::now()->addDays(3));
    holdCreditFor($held);

    app(CancelSubscription::class)->handle($this->subscription, 'طلب الطالب');

    expect($held->refresh()->status)->toBe(BookingStatus::Booked);
});

it('keeps a seat of a room size the plan never covered', function (): void {
    // A one-to-one hour booked with no hold (a cash-collecting workspace does
    // that too): not this group plan's seat, whatever it lacks.
    $private = paidSeat(CarbonImmutable::now()->addDays(3), ClassSessionType::Individual);

    app(CancelSubscription::class)->handle($this->subscription, 'طلب الطالب');

    expect($private->refresh()->status)->toBe(BookingStatus::Booked);
});

it('keeps a seat another live subscription still covers', function (): void {
    Subscription::factory()->create([
        'plan_id' => Plan::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'session_type' => ClassSessionType::Group,
            'coverage_type' => PlanCoverage::Workspace,
        ])->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => SubscriptionStatus::Active,
        'starts_on' => CarbonImmutable::today(),
        'ends_on' => CarbonImmutable::today()->addDays(29),
        'effective_ends_on' => CarbonImmutable::today()->addDays(29),
    ]);

    $seat = paidSeat(CarbonImmutable::now()->addDays(3));

    app(CancelSubscription::class)->handle($this->subscription, 'طلب الطالب');

    expect($seat->refresh()->status)->toBe(BookingStatus::Booked);
});

it('releases a subscription seat moved past the subscription’s last day, and keeps one moved inside it', function (): void {
    Queue::fake([FreezeBillableSeatsJob::class]);

    $movedOut = paidSeat(CarbonImmutable::now()->addDays(3));
    $movedIn = paidSeat(CarbonImmutable::now()->addDays(4));

    app(UpdateClassSession::class)->handle($movedOut->classSession()->firstOrFail(), [
        'starts_at' => CarbonImmutable::now()->addDays(40)->toIso8601String(),
    ]);
    app(UpdateClassSession::class)->handle($movedIn->classSession()->firstOrFail(), [
        'starts_at' => CarbonImmutable::now()->addDays(6)->toIso8601String(),
    ]);

    expect($movedOut->refresh()->status)->toBe(BookingStatus::Released)
        ->and($movedOut->is_billable)->toBeFalse()
        ->and($movedIn->refresh()->status)->toBe(BookingStatus::Booked);
});

it('never releases a credit-funded seat on a move', function (): void {
    Queue::fake([FreezeBillableSeatsJob::class]);

    $held = paidSeat(CarbonImmutable::now()->addDays(3));
    holdCreditFor($held);

    app(UpdateClassSession::class)->handle($held->classSession()->firstOrFail(), [
        'starts_at' => CarbonImmutable::now()->addDays(40)->toIso8601String(),
    ]);

    expect($held->refresh()->status)->toBe(BookingStatus::Booked);
});
