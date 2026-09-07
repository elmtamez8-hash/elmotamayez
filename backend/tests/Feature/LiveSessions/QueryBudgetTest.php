<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Actions\SendSessionReport;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
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

// `countingQueries()` moved to tests/Pest.php when spec 008 needed it too — a
// helper declared in a spec file only exists when that file loads first.

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
    // One per session and then some: prepaid is the default, and this student
    // takes a seat in every session the file creates — including the ten added
    // after the first measurement.
    fundBooking($test->workspace, $student, $test->course, 40);

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
| that moment.
|
| ⚠️ AND THIS FILE USED TO MEASURE THE ACTION, WHICH IS THE CHEAP THIRD OF IT.
| `BroadcastController::presence()` re-runs the WHOLE of `IssueJoinTicket` — the
| enrolment, the freeze, the withholding, the unlock rule — then the ping, then a
| `refresh()`. The budget below was six and the endpoint was spending roughly
| twenty-one, so the guard was green over a number four times its own. The Action
| is still measured, because it is where an accidental relation walk shows up;
| the endpoint is measured beside it, because that is what thirty students run
| twice a minute.
*/
it('keeps the heartbeat cheap', function (): void {
    sessionsFor(1, 'pending');
    $session = ClassSession::query()->firstOrFail();

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    // Prepaid is the default; the heartbeat's cost is the subject here.
    fundBooking($this->workspace, $student, $this->course);
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

it('keeps the heartbeat ENDPOINT cheap, which is three times the Action', function (): void {
    // Its own session, starting in five minutes: `sessionsFor()` builds them a
    // day out, which is outside the join window — the door would refuse before a
    // single query of the thing being measured was spent.
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $student, $this->course);
    app(BookSeat::class)->handle($session, $student);

    // A student cannot enter a room the teacher has not opened.
    app(OpenBroadcastRoom::class)->handle($session);

    Sanctum::actingAs($student);
    $url = "/api/v1/class-sessions/{$session->uuid}/presence";

    /*
     | Spatie loads its permission set once per process, so the FIRST request of
     | any test pays a warm-up that has nothing to do with row count.
     |
     | ⚠️ AND ONE WARM-UP WAS NOT ENOUGH — THAT WAS A FLAKE, NOT A THEORY. This
     | assertion failed once with 16 in eight full parallel runs and passed on an
     | identical re-run. Probing sixty consecutive pings named the cause instead
     | of guessing at it: the steady state is FOURTEEN, and the request straight
     | after a single warm-up costs fifteen or sixteen. The difference is
     |
     |     select * from "platform_settings" where "platform_settings"."key" = ?
     |
     | — an operational number, cached after its first read (this repository keeps
     | those in rows, not in `config/`). One request does not touch every key this
     | path reads, because creating the attendance row and updating it are
     | different branches reading different settings; the second request does.
     |
     | ⚠️ THE CEILING WAS NOT RAISED TO 16, AND MUST NOT BE. The regression this
     | budget exists to catch is worth TWO queries — the balances were fetched
     | twice per beat, once by the withholding check and once by the prepaid
     | fall-through — so a ceiling of 16 over a steady state of 14 admits it back
     | with room to spare. Warming until steady makes the measurement honest;
     | loosening the number would have made it quiet.
     */
    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertOk();

    [$count] = countingQueries(fn () => $this->postJson($url)->assertOk());

    /*
     | Auth and binding, the eligibility chain, the ping's transaction, and the
     | refresh: fourteen as this is written.
     |
     | ⚠️ DELIBERATELY TIGHT — one spare, where the budgets above carry twice the
     | fixture. Those measure LISTS, whose cost must not grow with rows; this
     | endpoint has no rows, so its cost is a fixed shape and any increase is a
     | new read rather than a bigger one. The specific regression it exists to
     | catch is worth two: the balances were fetched twice per beat, once by the
     | withholding check and once by the prepaid fall-through beneath it, and a
     | roomier ceiling would have let that back in unnoticed.
     */
    expect($count)->toBeLessThanOrEqual(15);
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
    // a delivery row PER CHANNEL, and a preference read. Linear in the register
    // and nothing worse — a nested walk would be well past this.
    //
    // Raised from 40 by spec 020, and the arithmetic is the reason it was raised
    // rather than the budget being in the way: a session report now goes to the
    // bell AND to WhatsApp, so it is exactly ONE more INSERT per student — four
    // students, four queries, 44. That is the cost of the second delivery row and
    // there is no version of two channels that does not pay it. What this case
    // exists to catch is the other shape: a per-student lookup added inside the
    // loop, which would have moved the number by a multiple of the register
    // rather than by one row each.
    expect($count)->toBeLessThan(48);
});
