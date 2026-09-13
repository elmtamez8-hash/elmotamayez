<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\ExcuseBooking;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · US3 · T069 · FR-014 — WHAT THE TEACHER IS PAID FOR.
|
| Three cases, and the middle one is the whole file. Before ٠٣٥ the wage base was
| the FROZEN SEAT and nothing else, so «twenty booked ⇒ twenty units» and «eight
| gave notice ⇒ twelve units» are both true of the old rule as well — cases (أ)
| and (ج) alone would be a green file over a build that reads no verdict at all.
|
| ⛔ WHAT DISCRIMINATES IS THE PAIR OF COLUMNS ON THE UNIT. Case (ب) is twelve
| ATTENDED and twenty CHARGED on one hour: the eight silent no-shows cost the
| teacher their whole lesson and pay for it (FR-008), while the eight who gave
| notice in case (أ) pay nothing and earn nothing. A single number cannot say
| both, which is why `attended_seats` is beside `charged_seats` rather than
| instead of it — and why asserting the unit COUNT alone proves nothing.
|
| ⚠️ PROVED BY KNOCK-OUT, not by reading. ONE swap was performed and measured:
| `$charged` replaced by `$attended` in the default arm of
| `AccrueTeachingUnits::handle()` — case (ب) fell from 50000 to 30000 and case (ج)
| from 9000 to 0. Restored, and the file is green again. The knock-out the comment
| does NOT claim, because it was not run: routing a zero-attendance session to
| `compensateEmptySession()`, which case (ج) would also catch.
|
| ⚠️ AND `fakeSessionTimeline()`, NEVER A BARE `Queue::fake()`. `AccrueUnitsOnDelivery`
| is queued, so a bare fake swallows it and every assertion below becomes a
| confident claim about an empty table.
*/

beforeEach(function (): void {
    fakeSessionTimeline();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    SettlementRate::factory()->group()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 2500,
    ]);

    // The factory's default session type is Individual; there is no state for it.
    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 9000,
    ]);
});

/** An hour with room for everybody the case needs. */
function paidOnSession(ClassSessionType $type, int $seats): ClassSession
{
    $test = test();

    return ClassSession::factory()->create([
        'teacher_profile_id' => $test->teacher->getKey(),
        'course_id' => $test->course->getKey(),
        'type' => $type,
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => $seats,
    ]);
}

/** Enrols a student, funds them and books the seat through the Action. */
function paidOnSeat(ClassSession $session): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    fundBooking($test->workspace, $student, $test->course);

    app(BookSeat::class)->handle($session->refresh(), $student);

    return $student;
}

/** A heartbeat from inside the room, then the stay it ended up with. */
function paidOnStay(ClassSession $session, User $user, int $seconds): void
{
    app(RecordPresencePing::class)->handle($session, $user);

    Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $user->getKey())
        ->update(['stay_seconds' => $seconds]);
}

/** Delivery: the teacher in the room, long enough, and the register closed. */
function paidOnTaught(ClassSession $session, int $frozenSeats): ClassSession
{
    $test = test();

    app(OpenBroadcastRoom::class)->handle($session->refresh());
    paidOnStay($session, $test->owner, 3000);

    // Frozen at the cancellation deadline in 005 and never recomputed (FR-060).
    $session->refresh()->forceFill(['billable_seats' => $frozenSeats])->save();

    return app(CloseClassSession::class)->handle($session->refresh());
}

it('pays for the twelve who stayed when the other eight gave notice in time', function (): void {
    // In time means before the deadline, and the deadline is derived from
    // `starts_at`. One minute of window is what makes «now» inside it for a
    // lesson five minutes away — the alternative is travelling the clock, which
    // would move the join window and the stay bar with it.
    PlatformSettings::set('sessions.cancellation_window_minutes', 1);

    $session = paidOnSession(ClassSessionType::Group, 20);

    $present = [];
    $gaveNotice = [];

    for ($i = 0; $i < 12; $i++) {
        $present[] = paidOnSeat($session);
    }

    for ($i = 0; $i < 8; $i++) {
        $gaveNotice[] = paidOnSeat($session);
    }

    foreach ($gaveNotice as $student) {
        $booking = SessionBooking::query()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->firstOrFail();

        app(CancelBooking::class)->handle($booking, 'ظرف طارئ');
    }

    foreach ($present as $student) {
        paidOnStay($session, $student, 3000);
    }

    $closed = paidOnTaught($session, 12);

    // A seat cancelled inside the window never reaches the register at all —
    // the loop reads `Booked` and `CancelledLate` — so twelve is the answer to
    // all three questions here, and that agreement is the point of the case.
    expect(TeachingUnit::query()->count())->toBe(12)
        ->and((int) $closed->attended_seats)->toBe(12)
        ->and((int) $closed->charged_seats)->toBe(12);

    $units = TeachingUnit::query()->get();

    expect($units->pluck('charged_seats')->unique()->all())->toBe([12])
        ->and($units->sum('amount_minor'))->toBe(12 * 2500);
});

it('pays for all twenty when the eight simply did not turn up', function (): void {
    $session = paidOnSession(ClassSessionType::Group, 20);

    $present = [];

    for ($i = 0; $i < 12; $i++) {
        $present[] = paidOnSeat($session);
    }

    for ($i = 0; $i < 8; $i++) {
        paidOnSeat($session);
    }

    foreach ($present as $student) {
        paidOnStay($session, $student, 3000);
    }

    $closed = paidOnTaught($session, 20);

    /*
    | ⛔ TWELVE AND TWENTY, ON ONE HOUR. The silent no-show is charged (FR-008)
    | and the teacher earns from them; the display number says twelve because
    | twelve is who was in the room. Assert BOTH: a build that pays on
    | `attended_seats` answers twelve here and is green on every other case in
    | this file.
    */
    expect((int) $closed->attended_seats)->toBe(12)
        ->and((int) $closed->charged_seats)->toBe(20)
        ->and(TeachingUnit::query()->count())->toBe(20);

    $units = TeachingUnit::query()->get();

    expect($units->pluck('attended_seats')->unique()->all())->toBe([12])
        ->and($units->pluck('charged_seats')->unique()->all())->toBe([20])
        ->and($units->sum('amount_minor'))->toBe(20 * 2500);
});

it('pays a whole fee for a one-to-one hour the student silently missed', function (): void {
    $session = paidOnSession(ClassSessionType::Individual, 1);
    paidOnSeat($session);

    $closed = paidOnTaught($session, 1);

    /*
    | ⛔ A WHOLE UNIT, NEVER THE EMPTY-SESSION COMPENSATION. Zero attendees is an
    | ordinary state now; zero CHARGED seats is what still means something went
    | wrong. Routing this session to `compensateEmptySession()` would pay a
    | percentage of the fee for an hour the teacher taught in full, and would
    | flag it for review as if the slot had been mis-scheduled.
    */
    $unit = TeachingUnit::query()->sole();

    expect((int) $closed->attended_seats)->toBe(0)
        ->and((int) $closed->charged_seats)->toBe(1)
        ->and($unit->amount_minor)->toBe(9000)
        ->and((int) $unit->attended_seats)->toBe(0)
        ->and((int) $unit->charged_seats)->toBe(1)
        ->and($unit->needs_review)->toBeFalse();
});

it('flags an hour that charged nobody, and leaves an ordinary empty room alone', function (): void {
    /*
    | ٠٣٥ · T072 — WHAT «SUSPICIOUS» MEANS AFTER THIS SHIPMENT.
    |
    | Zero ATTENDEES stopped being a signal — the case above is a full fee for an
    | empty room and is entirely ordinary. Zero CHARGED seats on a delivered hour
    | did not: seats were held, the teacher taught, and every single holder turned
    | out to be exempt. Telling those two apart is the whole use of the pair of
    | columns, and nothing else in the suite asks for it.
    |
    | ⚠️ AND THE FLAG USED TO FIRE FOR THE WRONG REASON — on any seat that merely
    | did not earn, reporting «no rate approved» about a teacher whose rate was
    | approved. The case above asserts it stays down; this one asserts it still
    | comes up when it should.
    */
    $session = paidOnSession(ClassSessionType::Group, 3);

    foreach (range(1, 3) as $ignored) {
        $student = paidOnSeat($session);

        $booking = SessionBooking::query()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->firstOrFail();

        app(ExcuseBooking::class)->handle($booking, $this->owner);
    }

    $closed = paidOnTaught($session, 3);

    expect((int) $closed->charged_seats)->toBe(0)
        ->and(TeachingUnit::query()->count())->toBe(3)
        ->and(TeachingUnit::query()->where('needs_review', true)->count())->toBe(3)
        ->and((int) TeachingUnit::query()->sum('amount_minor'))->toBe(0);
});
