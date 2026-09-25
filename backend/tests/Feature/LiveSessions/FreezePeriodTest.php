<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\MarkAbsenteesJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| SC-010 — inside a freeze: zero sessions created, zero absences counted, zero
| counters advanced.
|
| The freeze writes nothing to attendance rows or counters. It is READ by
| scheduling, booking and the counting jobs (research §R11) — which is exactly
| why FreezeResumptionTest can assert that resuming loses nothing: there is
| nothing to undo.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->holidayStart = CarbonImmutable::now()->addDays(5)->startOfDay();
    $this->holidayEnd = $this->holidayStart->addDays(7);
});

/** An enrolled student, ready to hold a seat. */
function frozenLearner(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    // Prepaid is the default, so a seat has to be paid for before it can be
    // taken. The subject of this file is a holiday, not money.
    fundBooking($test->workspace, $student, $test->course);
    // A seat in a group's session goes to a member of that group.
    joinTestGroup($student, (int) $test->course->getKey(), (int) $test->workspace->getKey());

    return $student;
}

function scheduleAt(CarbonImmutable $startsAt): ClassSession
{
    return app(ScheduleClassSession::class)->handle(
        new ScheduleSessionData(
            teacherProfileId: (int) test()->teacher->getKey(),
            // Required since Q-7 (spec 006) — the price is the course's.
            courseId: (int) test()->course->getKey(),
            title: 'حصة',
            type: ClassSessionType::Group,
            startsAt: $startsAt,
            durationMinutes: 60,
            seatsTotal: 5,
            // A group lesson names its group from the moment it is created.
            cohortId: groupCohortIdFor((int) test()->course->getKey(), (int) test()->workspace->getKey()),
        ),
        test()->owner,
    );
}

function freeze(?User $student = null): array
{
    return app(CreateFreezePeriod::class)->handle(
        test()->owner,
        test()->holidayStart,
        test()->holidayEnd,
        $student,
        'إجازة نصف العام',
    );
}

it('refuses to schedule inside a freeze', function (): void {
    freeze();

    expect(fn () => scheduleAt($this->holidayStart->addDays(2)->setHour(10)))
        ->toThrow(DomainException::class, 'لا يمكن جدولة حصة داخل فترة تجميد.');

    // And the day after it ends is untouched — a freeze is a period, not a stop.
    expect(scheduleAt($this->holidayEnd->addDay()->setHour(10)))
        ->toBeInstanceOf(ClassSession::class);
});

// ⚠️ THE FIRST DAY, which every test above missed by asking about a middle one.
//
// `covering()` compared `starts_on <= today`, and the DATE column holds
// `2026-08-14 00:00:00` because Eloquent writes a date-cast attribute through the
// model's datetime format. MySQL truncates that on insert, so production was
// right; SQLite keeps the string and `'…14 00:00:00' <= '…14'` is false — the
// freeze did not cover its own opening day, in the test suite only. Found while
// writing spec 006's exam-mode window, which copied this scope verbatim.
it('covers its own first day, and its last', function (): void {
    freeze();

    expect(fn () => scheduleAt($this->holidayStart->setHour(10)))
        ->toThrow(DomainException::class)
        ->and(fn () => scheduleAt($this->holidayEnd->setHour(10)))
        ->toThrow(DomainException::class);
});

// FR-040 — suspended, not deleted, and everybody who held a seat is told.
it('suspends the sessions already booked inside it and tells their seats', function (): void {
    $session = scheduleAt($this->holidayStart->addDays(2)->setHour(10));
    $student = frozenLearner();

    app(BookSeat::class)->handle($session->refresh(), $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $result = freeze();

    expect($result['suspended'])->toHaveCount(1)
        ->and($result['notified'])->toBe(1)
        ->and($session->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        // The seat went back rather than being held against the student: they
        // did nothing.
        ->and($session->bookings()->first()->status)->toBe(BookingStatus::Released)
        ->and(
            Notification::query()
                ->where('type', NotificationType::SessionCancelled->value)
                ->forRecipient($student)
                ->count()
        )->toBe(1);
});

it('refuses a booking inside a freeze', function (): void {
    // Scheduled first, frozen second — the calendar can be frozen after the fact.
    $session = scheduleAt($this->holidayStart->addDays(2)->setHour(10));
    $student = frozenLearner();

    freeze($student);

    // The session is suspended and the seat is gone; booking is refused twice
    // over, and either refusal is enough.
    expect(fn () => app(BookSeat::class)->handle($session->refresh(), $student))
        ->toThrow(DomainException::class);
});

// FR-041 — the holiday is not the student's absence.
it('counts no absence inside a freeze', function (): void {
    $session = scheduleAt($this->holidayStart->addDays(2)->setHour(10));
    $student = frozenLearner();

    app(BookSeat::class)->handle($session->refresh(), $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // The freeze is declared after the seat was taken, and the absentee sweep
    // runs anyway — the way a delayed job dispatched before the freeze would.
    freeze();

    app(MarkAbsenteesJob::class, ['classSessionId' => (int) $session->getKey()])
        ->handle(app(WorkspaceContext::class));

    expect(Attendance::query()->count())->toBe(0);
});

function scheduleIndividualFreezeSlot(CarbonImmutable $startsAt): ClassSession
{
    return app(ScheduleClassSession::class)->handle(
        new ScheduleSessionData(
            teacherProfileId: (int) test()->teacher->getKey(),
            courseId: (int) test()->course->getKey(),
            title: 'حصة فردية',
            type: ClassSessionType::Individual,
            startsAt: $startsAt,
            durationMinutes: 60,
            seatsTotal: 1,
        ),
        test()->owner,
    );
}

function sessionCancelledCount(?User $recipient = null): int
{
    return Notification::query()
        ->where('type', NotificationType::SessionCancelled->value)
        ->when($recipient !== null, fn ($query) => $query->forRecipient($recipient))
        ->count();
}

/*
| ⛔ A freeze on one student is theirs alone (FR-039, scenario 5) — and until this
| was fixed it was the WHOLE CLASS'S. The selection asked for the sessions the
| student held a seat in, and then suspended each one outright: every classmate's
| booking released, `seats_taken = 0`, every credit hold returned, and «لن تُعقد»
| sent to each of them about a group lesson that was still being taught.
|
| The test this replaces asserted exactly that — a group session `Suspended` by a
| freeze on one of its students — which is why the defect sat green.
*/
it('takes only the frozen student\'s seat out of a group session', function (): void {
    $session = scheduleAt($this->holidayStart->addDays(2)->setHour(10));

    $frozen = frozenLearner();
    $classmateA = frozenLearner();
    $classmateB = frozenLearner();

    foreach ([$frozen, $classmateA, $classmateB] as $student) {
        app(BookSeat::class)->handle($session->refresh(), $student);
        $this->setCurrentWorkspace($this->workspace, $this->owner);
    }

    expect($session->refresh()->seats_taken)->toBe(3);

    $result = freeze($frozen);

    $statusOf = fn (User $student): BookingStatus => $session->bookings()
        ->where('student_user_id', $student->getKey())
        ->firstOrFail()
        ->status;

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($session->seats_taken)->toBe(2)
        ->and($statusOf($frozen))->toBe(BookingStatus::Released)
        ->and($statusOf($classmateA))->toBe(BookingStatus::Booked)
        ->and($statusOf($classmateB))->toBe(BookingStatus::Booked)
        // Not suspended — listed as a released seat, so the teacher still sees it.
        ->and($result['suspended'])->toBe([])
        ->and($result['released'])->toHaveCount(1)
        ->and($result['notified'])->toBe(1)
        // Exactly one message on the platform, and it is the frozen student's.
        ->and(sessionCancelledCount())->toBe(1)
        ->and(sessionCancelledCount($frozen))->toBe(1);

    // The released seat is non-billable: the student did nothing.
    expect($session->bookings()->where('student_user_id', $frozen->getKey())->firstOrFail()->is_billable)
        ->toBeFalse();
});

// An individual session IS the student's alone, so it is suspended whole — and
// the teacher's other classes still carry on.
it('suspends the frozen student\'s individual session and nothing else', function (): void {
    $individual = scheduleIndividualFreezeSlot($this->holidayStart->addDays(2)->setHour(10));

    $frozen = frozenLearner();
    $other = frozenLearner();

    app(BookSeat::class)->handle($individual->refresh(), $frozen);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $second = scheduleAt($this->holidayStart->addDays(3)->setHour(10));
    app(BookSeat::class)->handle($second->refresh(), $other);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $result = freeze($frozen);

    expect($individual->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        ->and($second->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($result['suspended'])->toHaveCount(1)
        ->and($result['released'])->toBe([])
        ->and(sessionCancelledCount())->toBe(1)
        ->and(sessionCancelledCount($frozen))->toBe(1);
});
