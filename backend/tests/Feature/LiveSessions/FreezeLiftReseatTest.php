<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\DeleteFreezePeriod;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Events\CourseAccessShortened;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| Lifting a freeze early, for a group subscriber (owner decision 2026-09-25,
| option 3 — both halves).
|
| The month is 15 Oct → 13 Nov. A seven-day freeze from 20 Oct extends it to
| 20 Nov, and the claim takes the 17 Nov lesson that the extension bought. Then
| the freeze is lifted:
|
|   (b) the 22 Oct lesson — whose seat the freeze took — is no longer frozen,
|       and the subscriber gets the seat back without pressing anything;
|   (a) the 17 Nov lesson is past the end again, and its seat goes NOW, not
|       at the nightly sweep that used to be the only thing releasing it.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'));

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية التجميد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $this->cohort = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'created_by' => $this->teacher->getKey(),
        ]),
    );

    // Inside the freeze, and inside the month as sold.
    $this->frozenLesson = liftReseatSession(CarbonImmutable::parse('2026-10-22 09:00:00', 'UTC'));
    // After the month as sold (13 Nov), inside the seven days the freeze adds.
    $this->extensionLesson = liftReseatSession(CarbonImmutable::parse('2026-11-17 09:00:00', 'UTC'));

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $order = app(PurchaseSubscription::class)->handle(
        $this->student,
        (string) $this->plan->uuid,
        'cohort',
        (string) $this->cohort->uuid,
    );
    app(ApproveOrder::class)->handle($order, makePlatformStaff(Roles::FINANCE_ADMIN));

    $this->subscription = Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->firstOrFail();

    expect($this->subscription->effective_ends_on->toDateString())->toBe('2026-11-13')
        ->and(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Booked);
});

function liftReseatSession(CarbonImmutable $startsAt): ClassSession
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace(
        $test->workspace,
        fn (): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'course_id' => $test->course->getKey(),
            'cohort_id' => $test->cohort->getKey(),
            'type' => ClassSessionType::Group,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'seats_total' => 8,
            'seats_taken' => 0,
            'billable_seats' => null,
        ]),
    );
}

function liftReseatSeat(ClassSession $session): ?BookingStatus
{
    return SessionBooking::query()
        ->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', test()->student->getKey())
        ->first()
        ?->status;
}

function liftReseatFreeze(?User $student): FreezePeriod
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace(
        $test->workspace,
        fn (): FreezePeriod => app(CreateFreezePeriod::class)->handle(
            $test->teacher,
            CarbonImmutable::parse('2026-10-20'),
            CarbonImmutable::parse('2026-10-26'),
            $student,
            'إجازة',
        )['period'],
    );
}

function liftReseatLift(FreezePeriod $period): void
{
    app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn (): array => app(DeleteFreezePeriod::class)->handle($period),
    );
}

it('gives a subscriber back the seat a lifted STUDENT freeze took', function (): void {
    $period = liftReseatFreeze($this->student);

    expect(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Released);

    liftReseatLift($period);

    expect(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Booked)
        ->and((int) $this->frozenLesson->refresh()->seats_taken)->toBe(1);
});

it('gives a subscriber back the seat in a session a lifted WORKSPACE freeze suspended', function (): void {
    /*
    | ⚠️ THE ORDER IS THE TEST. The re-claim must run after `DeleteFreezePeriod`
    | has returned the session to `Scheduled`; run at the delete, it finds the
    | session still `Suspended`, skips it, and the subscriber is left seatless in
    | a lesson that is back on.
    */
    $period = liftReseatFreeze(null);

    expect($this->frozenLesson->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        ->and(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Released);

    liftReseatLift($period);

    expect($this->frozenLesson->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Booked)
        ->and((int) $this->frozenLesson->seats_taken)->toBe(1);
});

it('releases the seats past the new end the moment the freeze is lifted', function (): void {
    $period = liftReseatFreeze($this->student);

    expect($this->subscription->refresh()->effective_ends_on->toDateString())->toBe('2026-11-20')
        ->and(liftReseatSeat($this->extensionLesson))->toBe(BookingStatus::Booked);

    liftReseatLift($period);

    // No sweep has run: the subscription is still active, its enrolment still
    // open — only the end moved back.
    expect($this->subscription->refresh()->effective_ends_on->toDateString())->toBe('2026-11-13')
        ->and(liftReseatSeat($this->extensionLesson))->toBe(BookingStatus::Released)
        ->and((int) $this->extensionLesson->refresh()->seats_taken)->toBe(0)
        // The seat inside the month stays.
        ->and(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Booked);
});

it('keeps a seat whose hour an open-ended enrolment still covers', function (): void {
    /*
    | A null `expires_at` is access that does not expire. Read through `??` it is
    | indistinguishable from «no enrolment at all», and the one row that covers
    | every hour would release every seat.
    */
    $enrolment = fn () => Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->where('course_id', $this->course->getKey());

    $shorten = fn () => CourseAccessShortened::dispatch(
        (int) $this->workspace->getKey(),
        (int) $this->student->getKey(),
        [(int) $this->course->getKey()],
    );

    $enrolment()->update(['expires_at' => null]);
    $shorten();

    expect(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Booked);

    // And the control: an end before the lesson's hour does release it.
    $enrolment()->update(['expires_at' => CarbonImmutable::parse('2026-10-21 20:59:59', 'UTC')]);
    $shorten();

    expect(liftReseatSeat($this->frozenLesson))->toBe(BookingStatus::Released);
});
