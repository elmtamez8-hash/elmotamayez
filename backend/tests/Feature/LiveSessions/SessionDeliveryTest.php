<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Events\SessionCompleted;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-017 — no credit is consumed and no earning generated for a session the
| teacher did not deliver.
|
| Two ways to fail FR-056, tested separately because they are different
| mistakes: never turning up, and leaving early. Both end the session and
| neither delivers it, which is why SessionCompleted and SessionDelivered are two
| events rather than one carrying a flag — a flag makes the condition optional
| for whoever is listening.
*/

beforeEach(function (): void {
    /*
     * The delayed jobs are faked, and that is load-bearing rather than tidiness.
     *
     * On the `sync` connection the suite runs on, a job dispatched with ->delay()
     * runs IMMEDIATELY — the delay is a queue-driver feature, and sync has no
     * queue. So opening the room would run CloseClassSessionJob on the spot,
     * ending the session before the teacher had joined it, and this test would be
     * measuring a timeline that collapsed to a single instant.
     *
     * Their scheduling is asserted where it belongs, in AbsenceTimingTest.
     */
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
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'billable_seats' => 1,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->session, $this->student);
});

/** Puts the teacher in the room with a given stay. */
function teacherStayed(int $seconds): void
{
    $test = test();

    app(OpenBroadcastRoom::class)->handle($test->session);
    app(RecordPresencePing::class)->handle($test->session, $test->owner);

    Attendance::query()
        ->where('class_session_id', $test->session->getKey())
        ->where('student_user_id', $test->owner->getKey())
        ->update(['stay_seconds' => $seconds]);
}

it('delivers a session the teacher taught', function (): void {
    Event::fake([SessionCompleted::class, SessionDelivered::class]);

    // 80% of 60 minutes is 2880 seconds.
    teacherStayed(3000);

    app(CloseClassSession::class)->handle($this->session->refresh());

    expect($this->session->refresh()->delivered_at)->not->toBeNull();

    Event::assertDispatched(SessionCompleted::class);
    Event::assertDispatched(SessionDelivered::class, fn (SessionDelivered $event): bool => $event->billableSeats === 1);
});

it('does not deliver a session the teacher never joined', function (): void {
    Event::fake([SessionCompleted::class, SessionDelivered::class]);

    app(OpenBroadcastRoom::class)->handle($this->session);

    app(CloseClassSession::class)->handle($this->session->refresh());

    // It ended. It was not taught. Both facts are recorded, separately.
    Event::assertDispatched(SessionCompleted::class);
    Event::assertNotDispatched(SessionDelivered::class);

    expect($this->session->refresh()->delivered_at)->toBeNull();
});

it('does not deliver a session the teacher left early', function (): void {
    Event::fake([SessionCompleted::class, SessionDelivered::class]);

    teacherStayed(600);

    app(CloseClassSession::class)->handle($this->session->refresh());

    Event::assertNotDispatched(SessionDelivered::class);

    expect($this->session->refresh()->delivered_at)->toBeNull();
});

// SC-015 — nothing financial may precede attendance being confirmed, so the
// confirmation has to be a moment with a timestamp rather than an implication.
it('stamps every register row as confirmed when the session closes', function (): void {
    teacherStayed(3000);

    app(CloseClassSession::class)->handle($this->session->refresh());

    // The billable seats. The teacher has an attendance row too — they are a
    // participant in the register like anyone else — but they hold no seat, so
    // the register's completeness is measured against the seats.
    $rows = Attendance::query()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->whereNull('confirmed_at'))->toBeEmpty();
});
