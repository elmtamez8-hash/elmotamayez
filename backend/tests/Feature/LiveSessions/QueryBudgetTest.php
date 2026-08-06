<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Actions\SendSessionReport;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| SC-011 — a list must cost a fixed number of queries, not one per row.
|
| The assertions below are budgets, not exact counts: an extra query added by
| something unrelated should not fail the build, but a per-row query should.
| Each budget is written against TWICE the fixture size, so an N+1 cannot hide
| inside the allowance.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
});

/** @return array{0: int, 1: mixed} */
function countingQueries(callable $work): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = $work();

    if (getenv('DUMP_QUERIES') !== false) {
        foreach (DB::getQueryLog() as $q) {
            fwrite(STDERR, $q['query'].'
');
        }
    }

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$count, $result];
}

function sessionsFor(int $count, string $recordingStatus = 'published'): void
{
    $test = test();

    foreach (range(1, $count) as $index) {
        $session = ClassSession::factory()->create([
            'teacher_profile_id' => $test->teacher->getKey(),
            'course_id' => $test->course->getKey(),
            'starts_at' => CarbonImmutable::now()->addDays($index),
            'ends_at' => CarbonImmutable::now()->addDays($index)->addHour(),
            'duration_minutes' => 60,
            'seats_total' => 5,
        ]);

        // The interesting case: a published recording makes the resource look
        // for the lesson it became.
        $session->forceFill(['recording_status' => $recordingStatus])->save();
    }
}

/** An enrolled student holding a seat in every session. */
function bookedEverywhere(): User
{
    $test = test();
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);
    $test->createEnrollment($test->workspace, $test->course, $student);
    $test->setCurrentWorkspace($test->workspace, $test->owner);

    foreach (ClassSession::query()->get() as $session) {
        app(BookSeat::class)->handle($session, $student);
    }

    return $student;
}

it('lists the teacher calendar at a fixed cost', function (): void {
    sessionsFor(3);
    Sanctum::actingAs($this->owner);

    [$small] = countingQueries(fn () => $this->getJson('/api/v1/class-sessions')->assertOk());

    // Ten more sessions, each with a published recording.
    sessionsFor(10);

    [$large] = countingQueries(fn () => $this->getJson('/api/v1/class-sessions')->assertOk());

    // Not equality: the first request also warms the permission cache, so the
    // bigger list legitimately costs LESS. The invariant is the one that
    // matters — four times the rows must not cost more queries.
    expect($large)->toBeLessThanOrEqual($small);
});

it('serves the student schedule at a fixed cost', function (): void {
    sessionsFor(3);
    $student = bookedEverywhere();

    Sanctum::actingAs($student);

    [$small] = countingQueries(fn () => $this->getJson('/api/v1/schedule')->assertOk());

    sessionsFor(10);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    foreach (ClassSession::query()->whereDoesntHave('bookings')->get() as $session) {
        app(BookSeat::class)->handle($session, $student);
    }

    Sanctum::actingAs($student);

    [$large] = countingQueries(fn () => $this->getJson('/api/v1/schedule')->assertOk());

    expect($large)->toBeLessThanOrEqual($small);
});

it('serves the register at a fixed cost', function (): void {
    sessionsFor(1);
    $session = ClassSession::query()->firstOrFail();

    foreach (range(1, 3) as $index) {
        $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $student);
        $this->setCurrentWorkspace($this->workspace, $this->owner);

        Attendance::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'present',
            'auto_status' => 'present',
            'source' => 'automatic',
            'stay_seconds' => 3400,
        ]);
    }

    Sanctum::actingAs($this->owner);

    [$count] = countingQueries(
        fn () => $this->getJson("/api/v1/class-sessions/{$session->uuid}/attendance")->assertOk(),
    );

    // Session + attendances + students + the auth/permission reads. Nowhere near
    // one per row, which is what a missing `with('student')` would produce.
    expect($count)->toBeLessThan(12);
});

/*
| The heartbeat is the highest-frequency call in the product: one per
| participant per interval, for the whole length of every session running at
| that moment. Thirty students in a room is a hundred and twenty queries a
| minute at four each — so the count is asserted, not assumed.
*/
it('keeps the heartbeat cheap', function (): void {
    sessionsFor(1, 'pending');
    $session = ClassSession::query()->firstOrFail();

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(BookSeat::class)->handle($session, $student);

    // The first ping creates the row; every later one is the steady state, and
    // the steady state is what runs thousands of times.
    app(RecordPresencePing::class)->handle($session, $student);

    [$count] = countingQueries(
        fn () => app(RecordPresencePing::class)->handle($session, $student),
    );

    // Transaction + the row + the update, plus the settings lookup. Anything
    // that walks a relation from here shows up immediately.
    expect($count)->toBeLessThanOrEqual(6);
});

/*
| The report job runs once per finished session and touches every seat in it.
| Its cost may grow with the register — that is the work — but not faster.
*/
it('reports a full register without a query storm', function (): void {
    sessionsFor(1, 'pending');
    $session = ClassSession::query()->firstOrFail();

    foreach (range(1, 4) as $index) {
        $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $student);
        $this->setCurrentWorkspace($this->workspace, $this->owner);

        Attendance::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'absent',
            'auto_status' => 'absent',
            'source' => 'automatic',
            'stay_seconds' => 0,
        ]);
    }

    [$count] = countingQueries(function () use ($session): void {
        app(SendSessionReportsJob::class, [
            'classSessionId' => (int) $session->getKey(),
        ])->handle(
            app(WorkspaceContext::class),
            app(SendSessionReport::class),
        );
    });

    // Four students, each: a feedback lookup, a template render, a notification,
    // a delivery row, a preference read. Linear in the register and nothing
    // worse — a nested walk would be well past this.
    expect($count)->toBeLessThan(40);
});
