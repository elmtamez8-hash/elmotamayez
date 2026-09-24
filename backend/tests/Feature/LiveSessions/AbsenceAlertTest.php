<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\AbandonClassSession;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceConfirmed;
use App\Modules\LiveSessions\Listeners\SendAbsenceAlerts;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Tests\Support\FakeBroadcastProvider;

/*
| `attendance_alert` — the instant absence alert (owner decision 2026-09-24).
|
| The register becomes final and a seat holder is `absent` ⇒ the student and
| every guardian holding the ATTENDANCE consent hear it once, now. Never for a
| class nobody held, never for an excused seat, never for the host, never twice.
|
| `fakeSessionTimeline()` and NOT a bare `Queue::fake()`: the listener is queued,
| and a bare fake swallows it — every «nothing was sent» below would then pass
| against a build with no listener at all.
*/

beforeEach(function (): void {
    fakeSessionTimeline();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);
});

/** A funded, enrolled student holding a seat in this file's session. */
function absenceAlertSeatHolder(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);
    fundBooking($test->workspace, $student, $test->course, 5);

    app(BookSeat::class)->handle($test->session->refresh(), $student);

    return $student;
}

function absenceAlertCount(User $recipient): int
{
    return Notification::query()
        ->where('recipient_user_id', $recipient->getKey())
        ->where('type', NotificationType::AttendanceAlert->value)
        ->count();
}

it('tells an absent student and the guardian who holds the attendance consent, once', function (): void {
    $absent = absenceAlertSeatHolder();
    $present = absenceAlertSeatHolder();

    $attendanceGuardian = guardianOf($absent, [GuardianPermission::Attendance]);
    $resultsGuardian = guardianOf($absent, [GuardianPermission::Results]);
    $presentGuardian = guardianOf($present, [GuardianPermission::Attendance]);

    deliverBillableSession($this->session->refresh(), $this->owner, [$present]);

    $row = assertNotifiedOnce($absent, NotificationType::AttendanceAlert);
    assertNotifiedOnce($attendanceGuardian, NotificationType::AttendanceAlert);

    expect((string) $row->body)->toContain($this->session->title)
        ->and((string) $row->body)->toContain($absent->first_name)
        ->and(absenceAlertCount($resultsGuardian))->toBe(0)
        ->and(absenceAlertCount($present))->toBe(0)
        ->and(absenceAlertCount($presentGuardian))->toBe(0)
        // The host has an attendance row on purpose and is never a subject.
        ->and(absenceAlertCount($this->owner))->toBe(0);

    $stamp = Attendance::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $absent->getKey())
        ->value('absence_alerted_at');

    expect($stamp)->not->toBeNull();
});

it('sends nothing a second time when the confirmation is heard again', function (): void {
    $absent = absenceAlertSeatHolder();
    $guardian = guardianOf($absent, [GuardianPermission::Attendance]);

    $session = deliverBillableSession($this->session->refresh(), $this->owner, []);

    // A redelivered queued listener, and the same register re-announced.
    app(SendAbsenceAlerts::class)->handle(new AttendanceConfirmed($session));
    app(SendAbsenceAlerts::class)->handle(new AttendanceConfirmed($session->refresh()));

    expect(absenceAlertCount($absent))->toBe(1)
        ->and(absenceAlertCount($guardian))->toBe(1);
});

it('says nothing about a seat the teacher excused before the close', function (): void {
    $excused = absenceAlertSeatHolder();
    $guardian = guardianOf($excused, [GuardianPermission::Attendance]);

    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $excused->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    deliverBillableSession($this->session->refresh(), $this->owner, []);

    expect(absenceAlertCount($excused))->toBe(0)
        ->and(absenceAlertCount($guardian))->toBe(0);
});

it('says nothing about a row the register already marks excused', function (): void {
    $excused = absenceAlertSeatHolder();

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($excused): void {
        Attendance::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $this->session->getKey(),
            'student_user_id' => $excused->getKey(),
            'status' => AttendanceStatus::Excused,
            'auto_status' => AttendanceStatus::Absent,
            'source' => AttendanceSource::Manual,
            'stay_seconds' => 0,
        ]);
    });

    deliverBillableSession($this->session->refresh(), $this->owner, []);

    expect(absenceAlertCount($excused))->toBe(0);
});

it('says nothing when nobody held the class', function (): void {
    $absent = absenceAlertSeatHolder();
    $guardian = guardianOf($absent, [GuardianPermission::Attendance]);

    $this->session->refresh()->forceFill([
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHours(2),
    ])->save();

    app(AbandonClassSession::class)->handle($this->session->refresh());

    expect(absenceAlertCount($absent))->toBe(0)
        ->and(absenceAlertCount($guardian))->toBe(0);
});

it('says nothing about a session that was cancelled', function (): void {
    $absent = absenceAlertSeatHolder();

    app(CancelClassSession::class)->handle($this->session->refresh(), 'مرض المدرّس');
    // A stray close afterwards returns early on the terminal status.
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect(absenceAlertCount($absent))->toBe(0);
});

it('says nothing when the teacher left before the lesson counted', function (): void {
    $absent = absenceAlertSeatHolder();
    $guardian = guardianOf($absent, [GuardianPermission::Attendance]);

    // The room opened and closed with the teacher never staying: the register
    // is final, `AttendanceConfirmed` fires, and every seat reads `absent` —
    // about a class the TEACHER walked out of.
    app(OpenBroadcastRoom::class)->handle($this->session->refresh());
    $closed = app(CloseClassSession::class)->handle($this->session->refresh());

    expect($closed->refresh()->delivered_at)->toBeNull()
        ->and(absenceAlertCount($absent))->toBe(0)
        ->and(absenceAlertCount($guardian))->toBe(0);
});
