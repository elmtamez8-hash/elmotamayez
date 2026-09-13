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
use Tests\Support\FakeBroadcastProvider;

/*
| ⛔ INVERTED ON 2026-09-13, NOT DELETED — ٠٣٥ · FR-002 · T017.
|
| This file used to assert «attendance carries no financial weight whatsoever»
| (٠٠٥ · SC-024 · FR-023ج), and its proof was the right shape: two identical
| sessions, one where everybody attended and one where nobody did, with every
| financial fact held identical.
|
| ٠٣٥ keeps the comparison and splits the answer, because the owner's decision
| of 2026-09-13 is that there are now TWO seat counts and they mean different
| things:
|
|  · `billable_seats` — frozen at the cancellation deadline, unchanged by ٠٣٥,
|    and STILL identical across the two sessions. It answers «how many seats
|    were closed to everyone else», which attendance cannot retroactively alter.
|  · `attended_seats` — DIFFERENT. Two against zero. This is the number ٠٣٥
|    adds, and the whole of what this file now proves exists.
|  · `charged_seats` — IDENTICAL AGAIN, and that is the subtle half. The silent
|    no-show is charged: they gave nobody notice and the seat stood empty and
|    closed for the hour. So the empty session pays the teacher exactly what the
|    full one does, which is why `attended_seats` may never be the wage base.
|
| ⚠️ AND THE BARE `Queue::fake()` WENT WITH IT. It swallowed the queued charge
| listener along with the timeline jobs, so anything this file asserted about
| money was an assertion about an empty table. `fakeSessionTimeline()` is the one
| spelling of «the five jobs a `->delay()` would misfire, and nothing else».
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

it('separates the seat that was closed, the seat that sat, and the seat that pays', function (): void {
    Event::fake([SessionDelivered::class]);

    [$attended] = taughtSession(true);
    [$empty] = taughtSession(false);

    // Held constant: what was frozen at the deadline, and that both were taught.
    expect($attended->billable_seats)->toBe(2)
        ->and($empty->billable_seats)->toBe(2)
        ->and($attended->delivered_at)->not->toBeNull()
        ->and($empty->delivered_at)->not->toBeNull();

    /*
     | ⚠️ THIS PAIR IS WHAT FAILS ON A BUILD WITH NO ٠٣٥ IN IT: both columns are
     | null there, so `2` and `0` are the two assertions that cannot be reached
     | by accident. And they are asserted as an exact pair rather than as «not
     | equal», because a build that wrote `attended_seats` from the BOOKINGS
     | would produce 2 and 2 and read as working.
     */
    expect($attended->attended_seats)->toBe(2)
        ->and($empty->attended_seats)->toBe(0);

    /*
     | ⛔ AND THIS IS THE OWNER'S DECISION MEASURED. The empty session pays the
     | teacher exactly what the full one does, because the silent no-show is
     | charged — so `attended_seats` can never be the wage base, and a 1-on-1
     | whose student never showed would otherwise be routed to the empty-session
     | branch and paid a fraction while the student paid in full.
     */
    expect($attended->charged_seats)->toBe(2)
        ->and($empty->charged_seats)->toBe(2);

    Event::assertDispatchedTimes(SessionDelivered::class, 2);
    Event::assertDispatched(SessionDelivered::class, fn (SessionDelivered $event): bool => $event->billableSeats === 2);
    Event::assertDispatched(SessionDelivered::class, fn (SessionDelivered $event): bool => $event->chargedSeats === 2);
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
