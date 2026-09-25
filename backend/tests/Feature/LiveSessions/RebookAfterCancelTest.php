<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\ClaimSubscriptionSeats;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\DeleteFreezePeriod;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Three booking defects found by the 2026-09-25 verification audit.
|
| 1. A seat given up in time, or taken back by the system, could never be
|    booked again: the row stays (its status is the record), the unique index
|    on (session, student) refused the second INSERT, and the student read
|    «لديك مقعد محجوز في هذه الحصة بالفعل» about a seat they did not hold.
| 2. A 1:1 slot generated from availability is stamped with its booker's own
|    one-seat group at booking — and nothing took the stamp off when that seat
|    went, so the slot was refused to everyone else for ever.
| 3. A 1:1 slot could be booked one minute before it started; the lead time
|    `RequestPrivateSession` enforces was not asked at the booking door.
*/

beforeEach(function (): void {
    // Midday UTC, so a freeze's platform days and the UTC dates agree.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->profile = TeacherProfile::query()->withoutWorkspaceScope()
        ->whereKey($this->course->teacher_profile_id)->firstOrFail();

    $this->student = rebookStudent();
});

function rebookStudent(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    fundBooking($test->workspace, $student, $test->course);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    return $student;
}

function rebookSession(CarbonImmutable $startsAt, ClassSessionType $type = ClassSessionType::Group, ?int $cohortId = null): ClassSession
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace($test->workspace, function () use ($test, $startsAt, $type, $cohortId): ClassSession {
        $session = ClassSession::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'teacher_profile_id' => $test->profile->getKey(),
            'course_id' => $test->course->getKey(),
            'type' => $type,
            'seats_total' => $type === ClassSessionType::Individual ? 1 : 5,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'duration_minutes' => 60,
        ]);

        if ($cohortId !== null) {
            $session->forceFill(['cohort_id' => $cohortId])->save();
        }

        return $session;
    });
}

function rebookLiveHolds(ClassSession $session): int
{
    return DB::table('credit_holds')
        ->where('class_session_id', $session->getKey())
        ->whereNull('outcome')
        ->count();
}

// ─── 1 · rebooking ─────────────────────────────────────────────────────────

it('books a seat again after the student cancelled it in time, with a fresh credit hold', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addDays(3));

    $booking = app(BookSeat::class)->handle($session, $this->student);
    app(CancelBooking::class)->handle($booking);

    expect($session->refresh()->seats_taken)->toBe(0)
        ->and(rebookLiveHolds($session))->toBe(0);

    $again = app(BookSeat::class)->handle($session, $this->student);

    expect($again->getKey())->toBe($booking->getKey())
        ->and($again->refresh()->status)->toBe(BookingStatus::Booked)
        ->and($again->is_billable)->toBeTrue()
        ->and($again->cancelled_at)->toBeNull()
        ->and($session->refresh()->seats_taken)->toBe(1)
        ->and(rebookLiveHolds($session))->toBe(1)
        ->and(SessionBooking::query()->withoutWorkspaceScope()->where('class_session_id', $session->getKey())->count())->toBe(1);
});

it('books a seat again after the system released it', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addDays(3));

    $booking = app(BookSeat::class)->handle($session, $this->student);
    app(CancelBooking::class)->release($booking, 'اختبار');

    $again = app(BookSeat::class)->handle($session, $this->student);

    expect($again->refresh()->status)->toBe(BookingStatus::Booked)
        ->and($again->cancellation_reason)->toBeNull()
        ->and($session->refresh()->seats_taken)->toBe(1)
        ->and(rebookLiveHolds($session))->toBe(1);
});

it('undoes a late cancellation with no second seat and no second credit hold, once', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addHours(3));

    $booking = app(BookSeat::class)->handle($session, $this->student);
    app(CancelBooking::class)->handle($booking);

    expect($booking->refresh()->status)->toBe(BookingStatus::CancelledLate)
        ->and($session->refresh()->seats_taken)->toBe(1)
        ->and(rebookLiveHolds($session))->toBe(1);

    $undone = app(BookSeat::class)->handle($session, $this->student);

    expect($undone->getKey())->toBe($booking->getKey())
        ->and($undone->status)->toBe(BookingStatus::Booked)
        ->and($undone->cancelled_at)->toBeNull()
        ->and($session->refresh()->seats_taken)->toBe(1)
        ->and(rebookLiveHolds($session))->toBe(1)
        ->and(DB::table('credit_holds')->where('class_session_id', $session->getKey())->count())->toBe(1);

    // A double tap: the row is booked now, so the second press is a second
    // booking — refused, with nothing counted twice.
    expect(fn () => app(BookSeat::class)->handle($session, $this->student))
        ->toThrow(DomainException::class, 'لديك مقعد محجوز في هذه الحصة بالفعل.');

    expect($session->refresh()->seats_taken)->toBe(1)
        ->and(rebookLiveHolds($session))->toBe(1);
});

it('does not undo a late cancellation once the lesson has started', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addHours(3));

    $booking = app(BookSeat::class)->handle($session, $this->student);
    app(CancelBooking::class)->handle($booking);

    $this->travel(4)->hours();

    expect(fn () => app(BookSeat::class)->handle($session->refresh(), $this->student))
        ->toThrow(DomainException::class);

    expect($booking->refresh()->status)->toBe(BookingStatus::CancelledLate);
});

it('clears the reminder and the excuse of a seat that is booked back, so the reminder goes out again', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addDays(3));

    $booking = app(BookSeat::class)->handle($session, $this->student);
    $booking->forceFill(['reminded_at' => now(), 'excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()])->save();
    app(CancelBooking::class)->handle($booking);

    app(BookSeat::class)->handle($session, $this->student);

    $row = DB::table('session_bookings')->where('id', $booking->getKey())->first();

    expect($row->status)->toBe(BookingStatus::Booked->value)
        ->and($row->reminded_at)->toBeNull()
        ->and($row->excused_at)->toBeNull()
        ->and($row->excused_by_user_id)->toBeNull();
});

it('still refuses a second booking of a seat the student holds, giving the claimed capacity back', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addDays(3));

    app(BookSeat::class)->handle($session, $this->student);

    expect(fn () => app(BookSeat::class)->handle($session, $this->student))
        ->toThrow(DomainException::class, 'لديك مقعد محجوز في هذه الحصة بالفعل.');

    expect($session->refresh()->seats_taken)->toBe(1)
        ->and(rebookLiveHolds($session))->toBe(1);
});

it('does not let the automatic booker revive a seat the student cancelled themselves', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addDays(3));

    $booking = app(BookSeat::class)->handle($session, $this->student);
    app(CancelBooking::class)->handle($booking);

    $answer = app(ClaimSubscriptionSeats::class)->claimOneAsMember($session, $this->student, $booking->refresh());

    expect($answer)->toBe('')
        ->and($booking->refresh()->status)->toBe(BookingStatus::CancelledInWindow)
        ->and($session->refresh()->seats_taken)->toBe(0);
});

// ─── 2 · a generated 1:1 slot is not owned by whoever booked it first ──────

it('opens a generated 1:1 slot to other students once its booker cancels in time', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addDays(3), ClassSessionType::Individual);
    $other = rebookStudent();

    $booking = app(BookSeat::class)->handle($session, $this->student);

    expect($session->refresh()->cohort_id)->not->toBeNull();

    app(CancelBooking::class)->handle($booking);

    expect($session->refresh()->cohort_id)->toBeNull();

    $theirs = app(BookSeat::class)->handle($session, $other);

    expect($theirs->status)->toBe(BookingStatus::Booked)
        ->and($session->refresh()->cohort_id)->toBe(
            app(CohortDirectory::class)->ensureIndividualCohort(
                (int) $this->course->getKey(),
                (int) $this->workspace->getKey(),
                $other,
                null,
            ),
        );
});

it('opens a generated 1:1 slot again when a freeze on its booker is lifted', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addDays(10), ClassSessionType::Individual);
    $other = rebookStudent();

    app(BookSeat::class)->handle($session, $this->student);

    $period = app(CreateFreezePeriod::class)->handle(
        $this->owner,
        CarbonImmutable::now()->addDays(9),
        CarbonImmutable::now()->addDays(11),
        $this->student,
    )['period'];

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Suspended);

    app(DeleteFreezePeriod::class)->handle($period);

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($session->cohort_id)->toBeNull()
        ->and(app(BookSeat::class)->handle($session, $other)->status)->toBe(BookingStatus::Booked);
});

it('keeps a 1:1 session that was scheduled for one student filed under that student after they cancel', function (): void {
    $cohortId = app(CohortDirectory::class)->ensureIndividualCohort(
        (int) $this->course->getKey(),
        (int) $this->workspace->getKey(),
        $this->student,
        null,
    );
    $session = rebookSession(CarbonImmutable::now()->addDays(3), ClassSessionType::Individual, $cohortId);

    $booking = app(BookSeat::class)->handle($session, $this->student);
    app(CancelBooking::class)->handle($booking);

    expect($session->refresh()->cohort_id)->toBe($cohortId);
});

// ─── 3 · lead time on a 1:1 booking ────────────────────────────────────────

it('refuses a 1:1 slot that starts sooner than the minimum lead time', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addMinutes(30), ClassSessionType::Individual);

    expect(fn () => app(BookSeat::class)->handle($session, $this->student))
        ->toThrow(DomainException::class, 'اختر موعداً يبدأ بعد');

    expect($session->refresh()->seats_taken)->toBe(0);
});

it('books a 1:1 slot that starts after the minimum lead time', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addHours(3), ClassSessionType::Individual);

    expect(app(BookSeat::class)->handle($session, $this->student)->status)->toBe(BookingStatus::Booked);
});

it('does not apply the 1:1 lead time to a group lesson', function (): void {
    $session = rebookSession(CarbonImmutable::now()->addMinutes(30));

    expect(app(BookSeat::class)->handle($session, $this->student)->status)->toBe(BookingStatus::Booked);
});
