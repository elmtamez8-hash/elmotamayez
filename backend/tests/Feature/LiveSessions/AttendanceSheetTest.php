<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OverrideAttendance;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-022 — the register covers every frozen seat, no more and no less.
|
| A register listing only the people who turned up is a list of attendees, not a
| register: the parent of the child who did not come is precisely the person it
| exists for.
*/

beforeEach(function (): void {
    Queue::fake();
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
        'starts_at' => CarbonImmutable::now()->addDays(3),
        'ends_at' => CarbonImmutable::now()->addDays(3)->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);
});

/** An enrolled student holding a seat. */
function seatedLearner(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    app(BookSeat::class)->handle($test->session->refresh(), $student);

    return $student;
}

it('produces one row per frozen seat, nobody missing', function (): void {
    seatedLearner();
    seatedLearner();
    seatedLearner();

    (new FreezeBillableSeatsJob((int) $this->session->getKey()))->handle(app(WorkspaceContext::class));

    app(CloseClassSession::class)->handle($this->session->refresh());

    expect($this->session->refresh()->billable_seats)->toBe(3)
        ->and(Attendance::query()->where('class_session_id', $this->session->getKey())->count())->toBe(3);
});

// FR-060 · SC-018 — the number answers a question about a moment that passed.
it('keeps the frozen count when a seat is cancelled late afterwards', function (): void {
    $student = seatedLearner();
    seatedLearner();

    (new FreezeBillableSeatsJob((int) $this->session->getKey()))->handle(app(WorkspaceContext::class));

    // Past the deadline now, so this cancellation is billable and the seat stays
    // counted.
    $this->session->update([
        'starts_at' => CarbonImmutable::now()->addMinutes(30),
        'ends_at' => CarbonImmutable::now()->addMinutes(90),
    ]);

    $booking = $this->session->bookings()->where('student_user_id', $student->getKey())->first();
    app(CancelBooking::class)->handle($booking);

    expect($this->session->refresh()->billable_seats)->toBe(2);
});

// FR-061 — a teacher who turned up to an empty room is an operational fact
// worth a human look, not silence.
it('flags a session nobody booked', function (): void {
    (new FreezeBillableSeatsJob((int) $this->session->getKey()))->handle(app(WorkspaceContext::class));

    expect($this->session->refresh()->billable_seats)->toBe(0)
        ->and($this->session->interruption_note)->toBe('zero_attendance');
});

describe('manual override', function (): void {
    // FR-025 — the automatic verdict survives, visibly.
    it('records who changed it and keeps the automatic status', function (): void {
        $student = seatedLearner();

        (new FreezeBillableSeatsJob((int) $this->session->getKey()))->handle(app(WorkspaceContext::class));
        app(CloseClassSession::class)->handle($this->session->refresh());

        $attendance = Attendance::query()
            ->where('class_session_id', $this->session->getKey())
            ->where('student_user_id', $student->getKey())
            ->firstOrFail();

        expect($attendance->status)->toBe(AttendanceStatus::Absent);

        app(OverrideAttendance::class)->handle(
            $attendance,
            AttendanceStatus::Excused,
            $this->owner,
            'عذر طبّي',
        );

        $attendance->refresh();

        expect($attendance->status)->toBe(AttendanceStatus::Excused)
            ->and($attendance->source)->toBe(AttendanceSource::Manual)
            // What the system concluded is still on the record.
            ->and($attendance->auto_status)->toBe(AttendanceStatus::Absent)
            ->and($attendance->overridden_by)->toBe($this->owner->getKey())
            ->and($attendance->override_reason)->toBe('عذر طبّي');
    });

    // FR-022ب — a register that stays editable forever records nothing.
    it('refuses past the edit window without a higher permission', function (): void {
        $student = seatedLearner();

        app(CloseClassSession::class)->handle($this->session->refresh());

        $attendance = Attendance::query()
            ->where('class_session_id', $this->session->getKey())
            ->where('student_user_id', $student->getKey())
            ->firstOrFail();

        // Well past the 48-hour window.
        $this->session->update(['ends_at' => CarbonImmutable::now()->subDays(10)]);

        expect(fn () => app(OverrideAttendance::class)->handle(
            $attendance,
            AttendanceStatus::Present,
            $this->owner,
            'متأخّر',
        ))->toThrow(DomainException::class);
    });

    it('answers the coded refusal over HTTP', function (): void {
        $student = seatedLearner();
        app(CloseClassSession::class)->handle($this->session->refresh());

        $attendance = Attendance::query()
            ->where('class_session_id', $this->session->getKey())
            ->where('student_user_id', $student->getKey())
            ->firstOrFail();

        $this->session->update(['ends_at' => CarbonImmutable::now()->subDays(10)]);

        // A plain teacher, not the owner. The owner holds SETTINGS_UPDATE, which
        // IS the higher administrative permission FR-022ب allows through — so
        // acting as them would test the exception rather than the rule.
        $teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
        Sanctum::actingAs($teacher);

        $this->postJson("/api/v1/attendances/{$attendance->uuid}/override", [
            'status' => AttendanceStatus::Present->value,
            'reason' => 'متأخّر',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'attendance_window_closed');
    });

    // SC-014 — Excused is never automatic. It is a decision by a person.
    it('never produces Excused automatically', function (): void {
        seatedLearner();

        (new FreezeBillableSeatsJob((int) $this->session->getKey()))->handle(app(WorkspaceContext::class));
        app(CloseClassSession::class)->handle($this->session->refresh());

        $automatic = Attendance::query()
            ->where('class_session_id', $this->session->getKey())
            ->where('source', AttendanceSource::Automatic)
            ->get();

        expect($automatic->where('status', AttendanceStatus::Excused))->toBeEmpty();
    });
});
