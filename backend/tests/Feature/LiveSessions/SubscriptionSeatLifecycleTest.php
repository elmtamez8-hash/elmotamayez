<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\SubscriptionAccess;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| Spec 027 · US4 · FR-045 — the seats a finished subscription was holding.
|
| ⚠️ WHAT THIS MEASURED BEFORE THE FIX: nothing released a seat, anywhere.
| `SubscriptionAccess::close()` expired the enrolments and touched no booking;
| `CancelSubscription` reversed the money and touched no booking. So a student
| whose subscription ended on the 10th still held the rest of the month's seats —
| capacity a paying subscriber could not get — and `ChargeSessionSeats` reads
| seat holders by STATUS ALONE, so each of those sessions debited them a credit
| past the floor at delivery. Blocked from booking, deep in the negative, for
| classes they were locked out of and never asked for. `seat_holders` and
| `billable_seats` agreed perfectly the whole way.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => SubscriptionStatus::Active,
    ]);

    // The enrolment the subscription opened, found later by (order_id, source).
    $this->enrollment = Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'order_id' => $this->subscription->order_id,
        'source' => 'subscription',
        'status' => EnrollmentStatus::Active,
        'enrolled_at' => now(),
    ]);
});

function seatAt(CarbonImmutable $startsAt, ?int $courseId = null): SessionBooking
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace(
        $test->workspace,
        function () use ($test, $startsAt, $courseId): SessionBooking {
            $session = ClassSession::factory()->create([
                'workspace_id' => $test->workspace->getKey(),
                'course_id' => $courseId ?? $test->course->getKey(),
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

it('releases the seats that have not happened yet, and leaves the ones that have', function (): void {
    $future = seatAt(CarbonImmutable::now()->addWeek());
    $past = seatAt(CarbonImmutable::now()->subWeek());

    SubscriptionAccess::close($this->subscription);

    expect($future->refresh()->status)->toBe(BookingStatus::Released)
        // ⚠️ A class the student SAT IN happened. Their attendance, their seat
        // and the recording it produced are facts, and `billable_seats` was
        // frozen at that session's deadline and is never recomputed — reaching
        // back would move no money and must not try to.
        ->and($past->refresh()->status)->toBe(BookingStatus::Booked);
});

it('files it as RELEASED and unbillable, never as the student’s own cancellation', function (): void {
    $seat = seatAt(CarbonImmutable::now()->addWeek());

    SubscriptionAccess::close($this->subscription);

    /*
    | ⚠️ TWO THINGS RIDE ON THIS STATUS AND BOTH WERE WRONG WITH A CANCELLATION.
    | A release landing past the cancellation deadline would have written
    | `cancelled_late` with `is_billable = true` — the system taking the seat
    | away AND charging for it. And the automatic booker skips a cancelled row on
    | purpose (FR-044), so a student who lapses and then renews could never be
    | put back into these sessions: paid for, unbooked, silent.
    */
    expect($seat->refresh()->status)->toBe(BookingStatus::Released)
        ->and($seat->is_billable)->toBeFalse()
        ->and($seat->status->isBillable())->toBeFalse()
        ->and($seat->cancelled_at)->not->toBeNull();
});

it('gives the place back to the group', function (): void {
    $seat = seatAt(CarbonImmutable::now()->addWeek());

    SubscriptionAccess::close($this->subscription);

    // A leaked seat is a group that reads full with an empty chair in it — and
    // the whole point of FR-045 is the place going to a subscriber who can use it.
    expect((int) $seat->refresh()->classSession?->seats_taken)->toBe(0);
});

it('leaves alone a seat in a course the student bought outright', function (): void {
    /*
    | ⚠️ A SECOND COURSE, BECAUSE `enrollments` IS UNIQUE ON
    | (workspace, course, student). A student cannot hold both an outright and a
    | subscription enrolment in ONE course — `EnrollStudent` is `firstOrCreate`,
    | so an existing outright row simply survives, which is the case
    | `SubscriptionAccess` protects by keying on `(order_id, source)`.
    |
    | The real exposure is the neighbouring course: releasing by «this student,
    | this workspace, future sessions» would repossess seats behind a purchase
    | this subscription had nothing to do with — a month later and without a word.
    */
    $bought = courseWithRate((int) $this->workspace->getKey());
    $bought->forceFill(['status' => 'published'])->save();

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $bought->getKey(),
        'student_user_id' => $this->student->getKey(),
        'order_id' => null,
        'source' => 'purchase',
        'status' => EnrollmentStatus::Active,
        'enrolled_at' => now(),
    ]);

    $subscribed = seatAt(CarbonImmutable::now()->addWeek());
    $outright = seatAt(CarbonImmutable::now()->addWeek(), (int) $bought->getKey());

    SubscriptionAccess::close($this->subscription);

    expect($subscribed->refresh()->status)->toBe(BookingStatus::Released)
        ->and($outright->refresh()->status)->toBe(BookingStatus::Booked);
});

it('announces nothing when the subscription closed no enrolment at all', function (): void {
    $seat = seatAt(CarbonImmutable::now()->addWeek());

    // Already closed: a second sweep, a retried job, an officer cancelling an
    // expired subscription. Nothing left to take, so nothing is announced.
    $this->enrollment->forceFill(['status' => EnrollmentStatus::Expired])->save();

    expect(SubscriptionAccess::close($this->subscription))->toBe(0)
        ->and($seat->refresh()->status)->toBe(BookingStatus::Booked);
});

it('is safe to run twice', function (): void {
    $seat = seatAt(CarbonImmutable::now()->addWeek());

    SubscriptionAccess::close($this->subscription);
    SubscriptionAccess::close($this->subscription);

    // ⚠️ The seat count must not walk down past zero on the second pass — the
    // guarded decrement and the released-row skip are what stop it.
    expect((int) $seat->refresh()->classSession?->seats_taken)->toBe(0)
        ->and($seat->status)->toBe(BookingStatus::Released);
});
