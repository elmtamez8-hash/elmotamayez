<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Actions\RecordRecordingWatched;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-024 · FR-023ج — attendance carries no financial weight whatsoever.
|
| Written now, before billing exists, because the event 006 will consume is
| defined HERE. If the rule is only stated in prose, the phase that builds
| billing will read `status` because it is right there and looks relevant.
|
| The proof is a comparison: two identical sessions, same seats, one where
| everybody attended and one where nobody did. The financial facts — the frozen
| seat count and the delivery event — must be identical.
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
});

/**
 * A session with two booked seats, taught in full.
 *
 * @return array{0: ClassSession, 1: list<User>}
 */
function taughtSession(bool $studentsAttend): array
{
    $test = test();

    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $test->teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(2),
        'ends_at' => CarbonImmutable::now()->addMinutes(62),
        'duration_minutes' => 60,
        'seats_total' => 4,
    ]);

    $students = [];

    foreach (range(1, 2) as $ignored) {
        $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
        $test->createEnrollment($test->workspace, $test->course, $student);
        $test->setCurrentWorkspace($test->workspace, $test->owner);

        app(BookSeat::class)->handle($session->refresh(), $student);
        $students[] = $student;
    }

    (new FreezeBillableSeatsJob((int) $session->getKey()))->handle(app(WorkspaceContext::class));

    app(OpenBroadcastRoom::class)->handle($session->refresh());

    // The teacher always teaches: delivery is what billing hangs off, and it is
    // the variable being held constant here.
    app(RecordPresencePing::class)->handle($session, $test->owner);
    Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $test->owner->getKey())
        ->update(['stay_seconds' => 3600]);

    if ($studentsAttend) {
        foreach ($students as $student) {
            app(RecordPresencePing::class)->handle($session, $student);
            Attendance::query()
                ->where('class_session_id', $session->getKey())
                ->where('student_user_id', $student->getKey())
                ->update(['stay_seconds' => 3600, 'status' => AttendanceStatus::Present]);
        }
    }

    app(CloseClassSession::class)->handle($session->refresh());

    return [$session->refresh(), $students];
}

it('produces the same financial facts whether everyone attended or nobody did', function (): void {
    Event::fake([SessionDelivered::class]);

    [$attended] = taughtSession(true);
    [$empty] = taughtSession(false);

    expect($attended->billable_seats)->toBe(2)
        ->and($empty->billable_seats)->toBe(2)
        ->and($attended->delivered_at)->not->toBeNull()
        ->and($empty->delivered_at)->not->toBeNull();

    // Both delivered, both for the same number of seats. Attendance moved
    // nothing.
    Event::assertDispatchedTimes(SessionDelivered::class, 2);
    Event::assertDispatched(SessionDelivered::class, fn (SessionDelivered $event): bool => $event->billableSeats === 2);
});

// SC-023 · FR-021د — watching the recording is recorded and changes nothing.
it('does not let watching the recording flip a single status', function (): void {
    [$session, $students] = taughtSession(false);

    $before = Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->pluck('status', 'student_user_id');

    foreach ($students as $student) {
        app(RecordRecordingWatched::class)->handle($session, $student);
    }

    $after = Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->pluck('status', 'student_user_id');

    expect($after->toArray())->toEqual($before->toArray());

    // The watch itself IS recorded — it is a fact worth reporting, and a fair
    // reason for a teacher to mark someone present by hand. It simply does not
    // do so on its own.
    $watched = Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->whereNotNull('recording_watched_at')
        ->count();

    expect($watched)->toBe(2);
});
