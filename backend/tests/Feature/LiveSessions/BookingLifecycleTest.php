<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Actions\ReleaseIneligibleBookings;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create(['created_by' => $this->owner->getKey()]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

// FR-009 — cancelled in time, the seat goes back to the pool and nothing is owed.
it('releases the seat when cancelled inside the window', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addDays(5),
        'ends_at' => CarbonImmutable::now()->addDays(5)->addHour(),
    ]);

    $booking = app(BookSeat::class)->handle($session, $this->student);
    $booking = app(CancelBooking::class)->handle($booking);

    expect($booking->status)->toBe(BookingStatus::CancelledInWindow)
        ->and($booking->is_billable)->toBeFalse()
        ->and($session->refresh()->seats_taken)->toBe(0);
});

/*
| FR-010 — after the deadline the cancellation is still ACCEPTED, and still
| charged. Refusing it instead would leave the student marked absent from a
| session they told us they could not attend, which is a worse record of the
| same fact.
*/
it('accepts a late cancellation and keeps it billable', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addHour(),
        'ends_at' => CarbonImmutable::now()->addHours(2),
    ]);

    $booking = app(BookSeat::class)->handle($session, $this->student);
    $booking = app(CancelBooking::class)->handle($booking);

    expect($booking->status)->toBe(BookingStatus::CancelledLate)
        ->and($booking->is_billable)->toBeTrue()
        // The seat does NOT return to the pool, so the count frozen at the
        // deadline stays true (FR-060).
        ->and($session->refresh()->seats_taken)->toBe(1);
});

// FR-006 · FR-026 — nobody is charged and nothing enters a counter.
it('releases every seat when the teacher cancels the session', function (): void {
    $session = ClassSession::factory()->create(['teacher_profile_id' => $this->teacher->getKey()]);

    $booking = app(BookSeat::class)->handle($session, $this->student);

    app(CancelClassSession::class)->handle($session, 'ظرف طارئ');

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Cancelled)
        ->and($session->seats_taken)->toBe(0)
        // Released, not cancelled: the student did nothing.
        ->and($booking->refresh()->status)->toBe(BookingStatus::Released)
        ->and($booking->is_billable)->toBeFalse();
});

// FR-012 — eligibility lapses, the seat goes to somebody who can use it.
it('releases the seat of a student who is no longer eligible', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addDays(3),
        'ends_at' => CarbonImmutable::now()->addDays(3)->addHour(),
    ]);

    $booking = app(BookSeat::class)->handle($session, $this->student);

    // A freeze covering this student on the session's date is one of the three
    // conditions in FR-045, so it is enough to make them ineligible.
    FreezePeriod::factory()->create([
        'student_user_id' => $this->student->getKey(),
        'starts_on' => now()->addDay()->toDateString(),
        'ends_on' => now()->addDays(10)->toDateString(),
        'created_by' => $this->owner->getKey(),
    ]);

    $released = app(ReleaseIneligibleBookings::class)->handle($session->refresh());

    expect($released)->toBe(1)
        ->and($booking->refresh()->status)->toBe(BookingStatus::Released)
        ->and($session->refresh()->seats_taken)->toBe(0);
});

it('refuses to book a session that has already started', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->subMinutes(10),
        'ends_at' => CarbonImmutable::now()->addMinutes(50),
    ]);

    expect(fn () => app(BookSeat::class)->handle($session, $this->student))
        ->toThrow(DomainException::class);
});

it('refuses to book a cancelled session', function (): void {
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'status' => ClassSessionStatus::Cancelled,
    ]);

    expect(fn () => app(BookSeat::class)->handle($session, $this->student))
        ->toThrow(DomainException::class);
});
