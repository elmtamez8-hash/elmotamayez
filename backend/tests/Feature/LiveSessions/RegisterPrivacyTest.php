<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| The register is a list of named people and how long each of them stayed.
|
| SESSIONS_VIEW says "you may see the sessions you can book". ATTENDANCE_VIEW
| says "you may read who attended one". They are two questions, and answering
| the second with the first hands every student the class roll.
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

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addDays(3),
        'ends_at' => CarbonImmutable::now()->addDays(3)->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);
});

/** An enrolled student holding a seat. */
function classmate(): User
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

it('never hands one student the whole class roll', function (): void {
    $mine = classmate();
    $theirs = classmate();

    foreach ([$mine, $theirs] as $student) {
        Attendance::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $this->session->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'present',
            'auto_status' => 'present',
            'source' => 'automatic',
            'stay_seconds' => 3400,
        ]);
    }

    Sanctum::actingAs($mine);

    $response = $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/attendance");

    // A student may read their OWN row — that is ownership, not a permission —
    // and nothing about anyone else's.
    $uuids = collect($response->json('data'))->pluck('student.uuid')->filter()->all();

    expect($response->status())->toBe(200)
        ->and($uuids)->toBe([$mine->uuid])
        ->and($uuids)->not->toContain($theirs->uuid);
});

it('gives the teacher the full register', function (): void {
    classmate();
    classmate();

    Attendance::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->session->bookings()->first()->student_user_id,
        'status' => 'present',
        'auto_status' => 'present',
        'source' => 'automatic',
        'stay_seconds' => 3400,
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/attendance")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// NFR-001أ — a teacher must never read a row about someone with no active
// enrolment in their own workspace. A uuid they guessed is not an exception.
it('refuses to freeze a student who is not theirs', function (): void {
    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner();
    $stranger = $this->addWorkspaceMember($otherWorkspace, Roles::STUDENT);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $response = $this->postJson('/api/v1/freeze-periods', [
        'starts_on' => CarbonImmutable::now()->addDay()->toDateString(),
        'ends_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
        'student_uuid' => $stranger->uuid,
        'reason' => 'محاولة استكشاف',
    ]);

    expect($response->status())->toBe(422)
        // And above all: the stranger's name never crossed the wire.
        ->and($response->content())->not->toContain($stranger->name);
});

// A seat that was already given back is not news. The student cancelled it
// themselves, possibly weeks ago.
it('does not tell a released seat that its session was cancelled', function (): void {
    $staying = classmate();
    $leaving = classmate();

    app(CancelBooking::class)->handle(
        $this->session->bookings()->where('student_user_id', $leaving->getKey())->firstOrFail(),
    );

    app(CancelClassSession::class)->handle($this->session->refresh(), 'مرض المدرّس');

    $told = Notification::query()
        ->where('type', NotificationType::SessionCancelled->value)
        ->pluck('recipient_user_id')
        ->all();

    expect($told)->toBe([$staying->getKey()]);
});

// A freeze that suspends nothing still has to be honest about it.
it('reports zero notified when a freeze suspends nothing', function (): void {
    $result = app(CreateFreezePeriod::class)->handle(
        $this->owner,
        CarbonImmutable::now()->addMonths(6),
        CarbonImmutable::now()->addMonths(6)->addDays(3),
        null,
        'إجازة بعيدة',
    );

    expect($result['suspended'])->toBe([])
        ->and($result['notified'])->toBe(0);
});

/*
| ⚠️ THE HOST IS IN THE TABLE AND MUST NOT BE IN THE ROLL.
|
| The heartbeat records the teacher like any participant, and that row is the
| ONLY evidence `CloseClassSession` has that the session was delivered — the
| thing the teacher is paid on. So it cannot be removed.
|
| What it must not do is appear as a student. On 2026-08-18 the first real
| session showed «Demo Teacher» inside the teacher's own register, marked absent
| after two minutes in the room, beside a manual-attendance control and a note
| field labelled «تصل وليّ الأمر مع تقرير الحصة».
|
| Both halves are asserted here: gone from the roll, still in the table. A fix
| that deleted the row would pass the first assertion and stop every session from
| being billable.
*/
it('keeps the host out of the roll while keeping the row that proves delivery', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $teacherProfile = TeacherProfile::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
    ]);

    $session = ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $teacherProfile->getKey(),
    ]);

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    foreach ([$owner, $student] as $participant) {
        Attendance::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $participant->getKey(),
        ]);
    }

    Sanctum::actingAs($owner);

    $body = $this->getJson("/api/v1/class-sessions/{$session->uuid}/attendance")
        ->assertOk()
        ->json('data');

    expect(collect($body)->pluck('student.uuid'))
        ->toContain($student->uuid)
        ->not->toContain($owner->uuid);

    // Still there for the one reader that needs it.
    expect(Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $owner->getKey())
        ->exists())->toBeTrue();
});
