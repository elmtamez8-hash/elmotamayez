<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\AbandonClassSession;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\CloseStaleSessionsJob;
use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Tests\Support\FakeBroadcastProvider;

/*
| The teacher who never came.
|
| Every job that ends a session — the absentee sweep and the delayed close — is
| dispatched by the room OPENING. So a session nobody opened had nothing
| scheduled against it at all, and the hourly sweep did not select `scheduled`:
| the seats stayed held, no register existed, no guardian heard anything, and the
| row sat there for ever.
|
| The sharpest half is the counter. A teacher who opened the room and left early
| was marked down; one who never turned up was in NEITHER side of the ratio, so
| missing the lesson entirely was cheaper than teaching half of it.
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

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);
});

/** A student holding a seat on the session nobody will teach. */
function abandonedSeatHolder(): void
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    fundBooking($test->workspace, $student, $test->course);

    app(BookSeat::class)->handle($test->session->refresh(), $student);
}

/**
 * Runs the hourly sweep, with the clock past the staleness window.
 *
 * The travel is what makes this a test of the SWEEP rather than of the Action:
 * the selection is `ends_at < now - 6h`, and a fixture that called the Action by
 * hand would pass against a job that still selects nothing.
 */
function runStaleSweep(): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(8));

    try {
        app(CloseStaleSessionsJob::class)->handle(
            app(WorkspaceContext::class),
            app(CloseClassSession::class),
            app(AbandonClassSession::class),
        );
    } finally {
        CarbonImmutable::setTestNow();
    }
}

it('sweeps a session the teacher never opened, which nothing used to select', function (): void {
    abandonedSeatHolder();

    // The premise, asserted rather than assumed: nothing has scheduled anything
    // against this session, because the room was never opened.
    expect($this->session->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($this->session->room_opened_at)->toBeNull();

    runStaleSweep();

    expect($this->session->refresh()->status)->toBe(ClassSessionStatus::Interrupted)
        ->and($this->session->interruption_note)->toBe('teacher_no_show')
        ->and($this->session->delivered_at)->toBeNull();
});

it('marks nobody absent and tells no guardian about a lesson that never happened', function (): void {
    abandonedSeatHolder();
    abandonedSeatHolder();

    // Booking notifies the seat holder, so the number is not zero to begin with.
    // Measured as a DELTA rather than a total: a test asserting zero would be
    // asserting that booking is broken.
    $before = Notification::query()->count();

    runStaleSweep();

    // ⚠️ THE POINT OF THE SEPARATE ACTION. `CloseClassSession` writes an Absent
    // row for every frozen seat and then the report job tells each guardian their
    // child missed the class. Nobody was absent from a lesson nobody taught.
    //
    // The status line comes FIRST because it is what stops this passing
    // vacuously: the two counts below are equally true of a sweep that selected
    // nothing at all, which is precisely the bug being fixed.
    expect($this->session->refresh()->status)->toBe(ClassSessionStatus::Interrupted)
        ->and(Attendance::query()->where('class_session_id', $this->session->getKey())->count())->toBe(0)
        ->and(Notification::query()->count())->toBe($before);
});

it('counts the abandoned session against the teacher and never for them', function (): void {
    /*
     * ⚠️ A DELIVERED SESSION BESIDE IT, AND WITHOUT IT THIS TEST MEASURES NOTHING.
     *
     * A teacher with one abandoned session alone reads 0% whether the sweep ran
     * or not — `scheduled` is in neither side of the ratio, so the number is 0
     * from the missing denominator rather than from the missing delivery. With
     * one real delivery in the set the two answers separate: 100% if the
     * abandoned session is invisible (the bug), 50% if it counts (the fix).
     *
     * This is FR-062 stated as a number: never turning up must not be cheaper
     * than turning up and leaving early.
     */
    ClassSession::factory()->past()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    abandonedSeatHolder();
    runStaleSweep();

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    expect((int) $this->teacher->refresh()->attendance_rate)->toBe(50)
        ->and($this->teacher->completed_sessions_count)->toBe(1);
});

it('leaves a session that was actually taught to the ordinary close', function (): void {
    abandonedSeatHolder();

    $this->session->refresh()->forceFill([
        'status' => ClassSessionStatus::Live,
        'room_opened_at' => CarbonImmutable::now(),
    ])->save();

    runStaleSweep();

    // Completed, not Interrupted — the sweep still routes a live session through
    // the full close, register and all. Without this the new branch could swallow
    // every stale session and the file above would still be green.
    expect($this->session->refresh()->status)->toBe(ClassSessionStatus::Completed)
        ->and(Attendance::query()->where('class_session_id', $this->session->getKey())->count())->toBe(1);
});

it('carries on past a session whose close throws', function (): void {
    $poison = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        // A provider that is not in the map at all: the resolver throws, which is
        // what a decommissioned provider name looks like from here.
        'broadcast_provider' => 'a-provider-that-no-longer-exists',
        'status' => ClassSessionStatus::Live,
        'room_opened_at' => CarbonImmutable::now(),
        'seats_total' => 5,
    ]);

    // ⚠️ CREATED AFTER THE POISON, SO IT IS REACHED AFTER IT. That ordering is the
    // whole test: before the per-row try/catch, one unresolvable provider killed
    // the sweep on its first row — hourly, for ever — and every session behind it
    // in the list stayed live with no register and no report. A victim created
    // BEFORE the poison would pass against exactly that bug.
    $victim = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'seats_total' => 5,
    ]);

    expect((int) $poison->getKey())->toBeLessThan((int) $victim->getKey());

    runStaleSweep();

    expect($victim->refresh()->status)->toBe(ClassSessionStatus::Interrupted)
        ->and($poison->refresh()->status)->toBe(ClassSessionStatus::Live);
});
