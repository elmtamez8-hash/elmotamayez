<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\AssignSessionsToCohort;
use App\Modules\LiveSessions\Actions\ClaimSubscriptionSeats;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| Spec 027 · US3 · FR-039 … FR-045أ — the seats a subscription takes by itself.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    // ⚠️ `last_workspace_id` LEFT NULL. A student is a member of no workspace, so
    // the context is null for them in production; a fixture that stamps it
    // measures a person who does not exist.
    $this->student = User::factory()->create(['last_workspace_id' => null]);

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'subscription',
        'status' => EnrollmentStatus::Active,
        'enrolled_at' => now(),
    ]);

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'session_type' => ClassSessionType::Group,
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    // The live month itself. Without it the claim is correctly REFUSED — a
    // student with no subscription and no credits is withheld — so a fixture
    // that omits it measures the refusal rather than the feature.
    $this->subscription = Subscription::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'plan_id' => $this->plan->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => SubscriptionStatus::Active,
        'starts_on' => CarbonImmutable::now()->subDay(),
        'ends_on' => CarbonImmutable::now()->addDays(30),
        'effective_ends_on' => CarbonImmutable::now()->addDays(30),
    ]);

    $this->windowEnd = CarbonImmutable::now()->addDays(30);
});

function cohortSessionAt(CarbonImmutable $startsAt, ?int $seatsTotal = 8, ?int $billableSeats = null): ClassSession
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
            'seats_total' => $seatsTotal,
            'seats_taken' => 0,
            'billable_seats' => $billableSeats,
        ]),
    );
}

function claimSeatsForStudent(): array
{
    $test = test();

    return app(ClaimSubscriptionSeats::class)->handle(
        (int) $test->workspace->getKey(),
        $test->student,
        (int) $test->course->getKey(),
        (int) $test->cohort->getKey(),
        $test->windowEnd,
    );
}

it('books every session of the month without the student pressing anything', function (): void {
    $first = cohortSessionAt(CarbonImmutable::now()->addDays(2));
    $second = cohortSessionAt(CarbonImmutable::now()->addDays(9));

    $result = claimSeatsForStudent();

    expect($result['booked'])->toBe(2)
        ->and($result['refused'])->toBe([])
        ->and($first->refresh()->seats_taken)->toBe(1)
        ->and($second->refresh()->seats_taken)->toBe(1);
});

it('books a lesson inside the days a freeze added, which reading ends_on would lose', function (): void {
    /*
    | ⚠️ THE WINDOW IS `effective_ends_on`, NOT `ends_on`. The migration says so
    | in as many words: `ends_on` «never moves», `effective_ends_on` is «the only
    | one any predicate reads». A student frozen for two weeks owns two extra
    | weeks, and reading the sold date here takes them away — while 027's other
    | path (`liveOn()`) hands them over, so the two spellings of one window would
    | disagree by exactly the length of the freeze.
    */
    $subscription = $this->subscription;
    $subscription->forceFill([
        'starts_on' => CarbonImmutable::now()->subDays(20),
        'ends_on' => CarbonImmutable::now()->addDays(10),
        'effective_ends_on' => CarbonImmutable::now()->addDays(24),
    ])->save();

    $insideExtension = cohortSessionAt(CarbonImmutable::now()->addDays(20));

    $result = app(ClaimSubscriptionSeats::class)->handle(
        (int) $this->workspace->getKey(),
        $this->student,
        (int) $this->course->getKey(),
        (int) $this->cohort->getKey(),
        CarbonImmutable::parse($subscription->effective_ends_on),
    );

    expect($result['booked'])->toBe(1)
        ->and($insideExtension->refresh()->seats_taken)->toBe(1);
});

it('takes the last day of the window, which <= on a date column would drop', function (): void {
    // `effective_ends_on` is a DATE and `starts_at` a timestamp, so `<= end`
    // binds midnight and silently loses the whole of the last day paid for.
    cohortSessionAt(CarbonImmutable::parse($this->windowEnd)->setTime(19, 0));

    expect(claimSeatsForStudent()['booked'])->toBe(1);
});

it('skips a session whose billable count was already settled, and names it', function (): void {
    $frozen = cohortSessionAt(CarbonImmutable::now()->addDays(3), billableSeats: 4);

    $result = claimSeatsForStudent();

    expect($result['booked'])->toBe(0)
        ->and($result['refused'])->toHaveCount(1)
        ->and($result['refused'][0]['uuid'])->toBe($frozen->uuid)
        ->and($frozen->refresh()->seats_taken)->toBe(0);
});

it('revives a seat the system released and leaves a cancellation cancelled', function (): void {
    /*
    | ⚠️ THIS IS THE PAIR THAT «skip any existing row» WOULD COLLAPSE. Skipping
    | both makes FR-044 free and makes FR-045 kill renewal: a lapsed subscriber's
    | seats are RELEASED, they renew, and those lessons are skipped for ever —
    | paid for, unbooked, no error anywhere.
    */
    $released = cohortSessionAt(CarbonImmutable::now()->addDays(4));
    $cancelled = cohortSessionAt(CarbonImmutable::now()->addDays(5));

    seatRowFor($released, BookingStatus::Released);
    seatRowFor($cancelled, BookingStatus::CancelledInWindow);

    $result = claimSeatsForStudent();

    expect($result['booked'])->toBe(1)
        ->and(bookingFor($released)->status)->toBe(BookingStatus::Booked)
        ->and(bookingFor($released)->is_billable)->toBeTrue()
        ->and(bookingFor($released)->cancelled_at)->toBeNull()
        ->and(bookingFor($cancelled)->status)->toBe(BookingStatus::CancelledInWindow)
        // The revived seat is counted again; the cancelled one is not.
        ->and((int) $released->refresh()->seats_taken)->toBe(1)
        ->and((int) $cancelled->refresh()->seats_taken)->toBe(0);
});

it('never gives one student two seats in one session, however often it runs', function (): void {
    $session = cohortSessionAt(CarbonImmutable::now()->addDays(6));

    claimSeatsForStudent();
    claimSeatsForStudent();
    claimSeatsForStudent();

    expect(SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->count())->toBe(1)
        ->and((int) $session->refresh()->seats_taken)->toBe(1);
});

it('reports a full session instead of taking somebody else’s seat', function (): void {
    $full = cohortSessionAt(CarbonImmutable::now()->addDays(7), seatsTotal: 1);
    $classmate = User::factory()->create(['last_workspace_id' => null]);

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($full, $classmate): void {
        SessionBooking::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $full->getKey(),
            'student_user_id' => $classmate->getKey(),
            'status' => BookingStatus::Booked,
            'is_billable' => true,
            'booked_at' => now(),
        ]);

        $full->forceFill(['seats_taken' => 1])->save();
    });

    $result = claimSeatsForStudent();

    expect($result['booked'])->toBe(0)
        ->and($result['refused'])->toHaveCount(1)
        ->and((int) $full->refresh()->seats_taken)->toBe(1)
        ->and(bookingFor($full, $classmate)->status)->toBe(BookingStatus::Booked);
});

it('takes a seat for a subscriber whose credit balance is zero', function (): void {
    // FR-041: a subscriber's balance is legitimately empty — the month is what
    // pays. `EloquentAccountStanding::isWithheld()` asks the subscription first
    // for exactly this reason, and without a live one the seat is refused.
    cohortSessionAt(CarbonImmutable::now()->addDays(8));

    expect(claimSeatsForStudent()['booked'])->toBe(1);
});

it('settles a last-minute session’s billable count at its start, not at its birth', function (): void {
    /*
    | 027 · FR-039ب — the twin of the frozen-count skip, and a defect that
    | predates this feature. The cancellation deadline sits a fixed window before
    | the lesson, so a session the teacher creates for tomorrow morning has a
    | deadline in the PAST: the count settles at zero the moment it is created,
    | nobody can ever be counted in it, and the teacher is paid for an empty room
    | they taught a full hour in — whether the seats were taken automatically or
    | by hand.
    */
    $lastMinute = cohortSessionAt(CarbonImmutable::now()->addHours(2));
    $ordinary = cohortSessionAt(CarbonImmutable::now()->addDays(5));

    expect($lastMinute->billableSeatsFreezeAt()->equalTo($lastMinute->starts_at))->toBeTrue()
        ->and($ordinary->billableSeatsFreezeAt()->equalTo($ordinary->cancellationDeadline()))->toBeTrue()
        // And it is still bookable, which is the point: the count has not been
        // settled behind the subscriber's back.
        ->and(claimSeatsForStudent()['booked'])->toBe(2);
});

it('books the subscriber into a session that joins the group afterwards', function (): void {
    /*
    | 027 · FR-040 — the assignment path. `AssignSessionsToCohort` writes with a
    | bulk `update()`, which fires no model events at all, so a feature wired only
    | to `SessionScheduled` works when a lesson is created inside a group and is
    | SILENT when an existing one is moved into it. A scheduling-only test passes
    | green over exactly half a feature.
    */
    $unassigned = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $this->course->getKey(),
            'cohort_id' => null,
            'type' => ClassSessionType::Group,
            'starts_at' => CarbonImmutable::now()->addDays(6),
            'ends_at' => CarbonImmutable::now()->addDays(6)->addHour(),
            'seats_total' => 8,
            'seats_taken' => 0,
        ]),
    );

    CohortMembership::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->cohort->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'joined_at' => now(),
    ]);

    app(AssignSessionsToCohort::class)->handle($this->course, (string) $this->cohort->uuid, [(string) $unassigned->uuid]);

    expect(bookingFor($unassigned)->status)->toBe(BookingStatus::Booked)
        ->and((int) $unassigned->refresh()->seats_taken)->toBe(1);
});

function seatRowFor(ClassSession $session, BookingStatus $status): void
{
    $test = test();

    app(WorkspaceContext::class)->forWorkspace($test->workspace, function () use ($test, $session, $status): void {
        SessionBooking::query()->create([
            'workspace_id' => $test->workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $test->student->getKey(),
            'status' => $status,
            'is_billable' => false,
            'booked_at' => now()->subDay(),
            'cancelled_at' => now()->subHour(),
            'cancellation_reason' => 'تجهيزة',
        ]);
    });
}

function bookingFor(ClassSession $session, ?User $student = null): SessionBooking
{
    $row = SessionBooking::query()
        ->withoutWorkspaceScope()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', ($student ?? test()->student)->getKey())
        ->first();

    expect($row)->not->toBeNull();

    return $row;
}
