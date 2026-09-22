<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE STUDENT A TEACHER ONCE ADDED TO THEIR OWN WORKSPACE — and who then booked
| with somebody else.
|
| `users.last_workspace_id` is stamped by `addWorkspaceMember`, `AcceptInvitation`
| and the seeders, and `WorkspaceContext::id()` falls back to it — so for this
| student the scope ANDs workspace A onto every query about teacher B's session.
| The timetable (which bypasses the scope) listed the lesson; every door behind
| it answered 404: the page, the booking, the room, the heartbeat.
|
| `StudentWithoutWorkspaceTest` is the null-context mirror and could never see it.
|
| ⚠️ STAMPED WITH `forceFill`: `last_workspace_id` is in `User::$guarded`, so
| `create([...])` drops it in silence and rebuilds the null-context student —
| a case that passes against the broken build.
*/

beforeEach(function (): void {
    // A delay runs immediately on `sync`: the close job would stamp
    // `room_closed_at` inside the open itself and every later door is 403.
    Queue::fake([CloseClassSessionJob::class]);

    [$this->workspace, $owner] = $this->createWorkspaceWithOwner();
    [$this->elsewhere] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $owner);

    $teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $owner->getKey(),
    ]);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    $this->student = User::factory()->create();
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    fundBooking($this->workspace, $this->student, $this->course);

    $this->student->forceFill(['last_workspace_id' => $this->elsewhere->getKey()])->save();
    expect($this->student->refresh()->last_workspace_id)->toBe($this->elsewhere->getKey());

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $this->uuid = $this->session->uuid;
});

it('books a seat, and the seat is written into the TEACHER\'s workspace', function (): void {
    $this->postJson("/api/v1/class-sessions/{$this->uuid}/book")->assertCreated();

    $booking = SessionBooking::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->sole();

    // ⚠️ The write side: `BelongsToWorkspace` fills the id from the WRITER's
    // context, which for this student is the other workspace.
    expect($booking->workspace_id)->toBe($this->workspace->getKey());
});

it('opens the session page, its register and its refusal reason', function (): void {
    $this->postJson("/api/v1/class-sessions/{$this->uuid}/book")->assertCreated();
    $this->asGuest();

    $this->getJson("/api/v1/class-sessions/{$this->uuid}")
        ->assertOk()
        ->assertJsonPath('uuid', $this->uuid)
        // The eager load, not only the binding: a scoped `bookings` load is an
        // empty list, and the page stops recognising the seat it just booked.
        ->assertJsonPath('my_booking.status', 'booked');

    $this->getJson("/api/v1/class-sessions/{$this->uuid}/attendance")->assertOk();

    $this->getJson("/api/v1/class-sessions/{$this->uuid}/eligibility")
        ->assertOk()
        ->assertJsonPath('data.session_uuid', $this->uuid);
});

it('enters the room, keeps its heartbeat, and is registered in the teacher\'s workspace', function (): void {
    $this->postJson("/api/v1/class-sessions/{$this->uuid}/book")->assertCreated();
    // The HOST opens the room, from the teacher's own workspace — not under the
    // student's context, where the claim would miss the row.
    app(WorkspaceContext::class)->forWorkspace($this->workspace, fn () => app(OpenBroadcastRoom::class)->handle($this->session->refresh()));
    $this->asGuest();

    $this->postJson("/api/v1/class-sessions/{$this->uuid}/join")->assertOk();
    $this->postJson("/api/v1/class-sessions/{$this->uuid}/presence")->assertOk();
    // A second beat updates the row the first one wrote — a scoped read would
    // miss it and collide with the unique index instead.
    $this->postJson("/api/v1/class-sessions/{$this->uuid}/presence")->assertOk();
    $this->getJson("/api/v1/class-sessions/{$this->uuid}/participants")->assertOk();

    $attendance = Attendance::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->sole();

    expect($attendance->workspace_id)->toBe($this->workspace->getKey());
});

it('shows the booked seat on the course\'s sessions tab', function (): void {
    $this->postJson("/api/v1/class-sessions/{$this->uuid}/book")->assertCreated();
    $this->asGuest();

    $row = collect($this->getJson("/api/v1/courses/{$this->course->uuid}/sessions")->assertOk()->json('data'))
        ->firstWhere('uuid', $this->uuid);

    expect($row)->not->toBeNull()
        ->and($row['my_booking'] ?? null)->not->toBeNull();
});
