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

// A freeze on one student is theirs alone (FR-039, scenario 5).
it('leaves the teacher other students alone when one is frozen', function (): void {
    $session = scheduleAt($this->holidayStart->addDays(2)->setHour(10));

    $frozen = frozenLearner();
    $other = frozenLearner();

    app(BookSeat::class)->handle($session->refresh(), $frozen);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $second = scheduleAt($this->holidayStart->addDays(3)->setHour(10));
    app(BookSeat::class)->handle($second->refresh(), $other);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    freeze($frozen);

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        ->and($second->refresh()->status)->toBe(ClassSessionStatus::Scheduled);
});
