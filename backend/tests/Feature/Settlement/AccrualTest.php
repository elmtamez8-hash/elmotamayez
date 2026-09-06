<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\SettlementBasis;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-005ب · SC-005د · SC-002 — the accrual itself.
|
| The unit is the SEAT, not the attendee. Ten frozen seats with six people
| present is ten units: the four who did not come still receive the recording,
| the files and the homework, so the teacher delivered the package to all ten
| (Q2ب). The absence risk sits with the student, not with the teacher and not
| with the platform.
|
| Queue::fake() throughout: a ->delay() on the `sync` connection runs
| IMMEDIATELY, so opening the room would close the session before the teacher
| joined and every timeline here would collapse into one instant.
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

    $this->rate = SettlementRate::factory()->group()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 2500,
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'type' => ClassSessionType::Group,
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 10,
    ]);
});

/** Enrols a student and books them a seat. */
function seatHolder(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    // Prepaid is the default, so a seat has to be paid for before it can be
    // taken. The subject of this file is not money; the funding is fixture.
    fundBooking($test->workspace, $student, $test->course);

    app(BookSeat::class)->handle($test->session->refresh(), $student);

    return $student;
}

/** Puts the teacher in the room for a given stay, which is what delivery means. */
function teacherTaught(int $seconds): void
{
    $test = test();

    app(OpenBroadcastRoom::class)->handle($test->session->refresh());
    app(RecordPresencePing::class)->handle($test->session, $test->owner);

    Attendance::query()
        ->where('class_session_id', $test->session->getKey())
        ->where('student_user_id', $test->owner->getKey())
        ->update(['stay_seconds' => $seconds]);
}

it('generates one unit per frozen seat, not per attendee', function (): void {
    for ($i = 0; $i < 10; $i++) {
        seatHolder();
    }

    // Frozen at the cancellation deadline in 005 and never recomputed after.
    $this->session->refresh()->forceFill(['billable_seats' => 10])->save();

    teacherTaught(3000);
    app(CloseClassSession::class)->handle($this->session->refresh());

    // Ten, compared against the FROZEN SEAT COUNT rather than against the
    // register. A test that counted attendance rows would pass today and hide
    // the bug the day someone made presence the basis (FR-025د).
    expect(TeachingUnit::query()->count())->toBe(10)
        ->and((int) $this->session->refresh()->billable_seats)->toBe(10);
});

it('prices each unit from the rate in force and stores the amount beside the reference', function (): void {
    seatHolder();
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    teacherTaught(3000);
    app(CloseClassSession::class)->handle($this->session->refresh());

    $unit = TeachingUnit::query()->first();

    // Both, on purpose: the reference alone reprices the past the first time
    // anyone corrects a rate row, the amount alone loses the reason (FR-007ب).
    expect($unit)->not->toBeNull()
        ->and($unit->amount_minor)->toBe(2500)
        ->and($unit->settlement_rate_id)->toBe($this->rate->getKey())
        ->and($unit->frozen_seats)->toBe(1);
});

it('generates nothing for a session the teacher never joined', function (): void {
    seatHolder();
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    app(OpenBroadcastRoom::class)->handle($this->session->refresh());
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect(TeachingUnit::query()->count())->toBe(0);
});

it('generates nothing for a session the teacher left early', function (): void {
    seatHolder();
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    teacherTaught(600);
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect(TeachingUnit::query()->count())->toBe(0);
});

/*
| The condition lives in 005, in CloseClassSession, and this asserts that it is
| still there rather than duplicated into a listener here. If SessionDelivered
| fires at all for an undelivered session, the bug is upstream and the two tests
| above would be checking our workaround instead of the rule.
*/
it('never sees the event at all for an undelivered session', function (): void {
    Event::fake([SessionDelivered::class]);

    seatHolder();
    teacherTaught(600);
    app(CloseClassSession::class)->handle($this->session->refresh());

    Event::assertNotDispatched(SessionDelivered::class);
});

it('generates one unit per seat however often the event arrives', function (): void {
    seatHolder();
    seatHolder();
    $this->session->refresh()->forceFill(['billable_seats' => 2])->save();

    teacherTaught(3000);
    app(CloseClassSession::class)->handle($this->session->refresh());

    // A retried queue job, a replayed webhook, an operator running the sweep
    // twice: all of them land here, and paying twice for one hour taught once is
    // the failure this guards (FR-002 · SC-002).
    for ($i = 0; $i < 10; $i++) {
        SessionDelivered::dispatch($this->session->refresh(), 2, []);
    }

    expect(TeachingUnit::query()->count())->toBe(2);
});

it('pays a subscriber’s seat by who turned up, not by who was booked', function (): void {
    /*
    | 027 · FR-048 + the product decision of 2026-09-05. Automatic booking puts
    | every member of the group into every lesson, so the seat count stopped being
    | evidence of anything the moment nobody had to press «احجز» for it to exist.
    | Paying per BOOKED subscriber pays the teacher for people who were never in
    | the room; paying per ATTENDING subscriber pays for the hour actually taught.
    |
    | ⚠️ AND THE ROW IS WRITTEN EITHER WAY. A seat that produced no unit at all is
    | an hour the statement cannot show, and the teacher counts differently from us.
    */
    $paying = seatHolder();
    $cameToClass = seatHolder();
    $stayedHome = seatHolder();
    $this->session->refresh()->forceFill(['billable_seats' => 3])->save();

    teacherTaught(3000);
    app(RecordPresencePing::class)->handle($this->session->refresh(), $cameToClass);

    app(CloseClassSession::class)->handle($this->session->refresh());

    TeachingUnit::query()->delete();

    SessionDelivered::dispatch(
        $this->session->refresh(),
        3,
        [(int) $cameToClass->getKey(), (int) $stayedHome->getKey()],
    );

    $attendedUnit = TeachingUnit::query()->where('student_user_id', $cameToClass->getKey())->first();
    $absentUnit = TeachingUnit::query()->where('student_user_id', $stayedHome->getKey())->first();
    $payingUnit = TeachingUnit::query()->where('student_user_id', $paying->getKey())->first();

    expect(TeachingUnit::query()->count())->toBe(3)
        ->and($attendedUnit?->basis)->toBe(SettlementBasis::SubscriptionSeat)
        ->and((int) $attendedUnit?->amount_minor)->toBeGreaterThan(0)
        ->and($absentUnit?->basis)->toBe(SettlementBasis::SubscriptionSeat)
        ->and((int) $absentUnit?->amount_minor)->toBe(0)
        // ⚠️ AND THE ABSENTEE IS NOT FLAGGED FOR REVIEW. «No rate approved» and
        // «paid for by a month nobody used» are two different zeros, and folding
        // them together buries the first under a queue of the second.
        ->and($absentUnit?->needs_review)->toBeFalse()
        /*
        | ⚠️ AND THE SEAT SOMEBODY BOUGHT OUTRIGHT IS UNTOUCHED, EVEN THOUGH THEY
        | DID NOT COME EITHER. Attendance has no financial effect anywhere else in
        | this product — they bought the SEAT and it is theirs whether they use it
        | — and this test failing on that line means the rule leaked out of the
        | subscription lane.
        */
        ->and($payingUnit?->basis)->toBe(SettlementBasis::FrozenSeat)
        ->and((int) $payingUnit?->amount_minor)->toBeGreaterThan(0);
});

it('carries no reference to anything the student paid', function (): void {
    seatHolder();
    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    teacherTaught(3000);
    app(CloseClassSession::class)->handle($this->session->refresh());

    $columns = array_keys(TeachingUnit::query()->first()?->getAttributes() ?? []);

    // FR-003, asserted on the row rather than trusted to the migration: a column
    // added later "just for reference" is exactly how a separation erodes.
    foreach (['order_id', 'payment_id', 'receipt_id', 'credit_transaction_id', 'amount_paid'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});
