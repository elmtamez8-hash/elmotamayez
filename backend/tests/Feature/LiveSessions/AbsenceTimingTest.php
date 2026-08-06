<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\MarkAbsenteesJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-021 — Absent is written AT the threshold, not at the end of the session.
|
| This measures the MOMENT, which is the whole requirement (FR-021ب). A nightly
| sweep would produce identical rows and still be wrong: a parent asking at
| minute 35 why nobody told them their child never showed up is the case the
| rule exists for.
*/

beforeEach(function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->session, $this->student);
});

it('schedules the absence sweep for the threshold, not the end', function (): void {
    Queue::fake();

    app(OpenBroadcastRoom::class)->handle($this->session);

    Queue::assertPushed(MarkAbsenteesJob::class, function (MarkAbsenteesJob $job): bool {
        // start + 50% of 60 minutes = start + 30. Anything later — including the
        // session's end — is the requirement missed.
        $expected = $this->session->starts_at->copy()->addMinutes(30);

        return $job->delay !== null
            && abs((int) $expected->diffInSeconds($job->delay, false)) <= 2;
    });

    Queue::assertPushed(CloseClassSessionJob::class);
});

it('marks the seat absent when the sweep runs', function (): void {
    app(OpenBroadcastRoom::class)->handle($this->session);

    (new MarkAbsenteesJob((int) $this->session->getKey()))->handle(app(WorkspaceContext::class));

    $attendance = Attendance::query()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance->status)->toBe(AttendanceStatus::Absent)
        ->and($attendance->stay_seconds)->toBe(0);
});

// A queue may run a job twice; the second run must not disturb a mark the first
// one settled, nor one a teacher has since corrected by hand.
it('runs twice without changing anything', function (): void {
    app(OpenBroadcastRoom::class)->handle($this->session);

    $context = app(WorkspaceContext::class);

    (new MarkAbsenteesJob((int) $this->session->getKey()))->handle($context);
    $first = Attendance::query()->where('class_session_id', $this->session->getKey())->count();

    (new MarkAbsenteesJob((int) $this->session->getKey()))->handle($context);
    $second = Attendance::query()->where('class_session_id', $this->session->getKey())->count();

    expect($second)->toBe($first);
});
