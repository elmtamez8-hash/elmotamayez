<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-003 — every attempt to enter without the right to be there is refused.
|
| Four ways to be wrong, one answer to all of them. A refusal that distinguishes
| "no seat" from "wrong time" from "already closed" tells a caller which of those
| is true, and that is an enumeration tool: it confirms the session exists,
| leaks its schedule, and says when to come back (FR-015 · scenario 2).
*/

beforeEach(function (): void {
    // Opening the room dispatches two delayed jobs, and on the `sync` connection
    // the suite runs on a delay is not honoured — CloseClassSessionJob would run
    // on the spot and shut the door being tested. Their scheduling is asserted in
    // AbsenceTimingTest, where it is the subject rather than a side effect.
    Queue::fake();

    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    // Scheduled and about to start: inside the join window (±15 min) yet still
    // in the future, so a seat can be taken. Booking a session that has already
    // begun is refused by design, so a `live()` session cannot be set up from
    // the outside in.
    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

/** Books the seat and returns the student, so eligibility is not what refuses. */
function seatedStudent(User $student): User
{
    app(BookSeat::class)->handle(test()->session, $student);

    return $student;
}

it('lets the teacher in as host and opens the room', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")
        ->assertOk()
        ->assertJsonPath('role', 'host')
        // No provider name, no key, no secret — the ticket is handed to a
        // browser, so anything secret in it is public (FR-019).
        ->assertJsonMissingPath('provider')
        ->assertJsonStructure(['room_url', 'token', 'expires_at', 'role', 'presence_interval_seconds']);

    expect($this->session->refresh()->broadcast_room_id)->not->toBeNull();
});

it('lets a student with a live seat in as participant', function (): void {
    seatedStudent($this->student);

    // The host opens the room first; a student does not start a session the
    // teacher has not.
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")
        ->assertOk()
        ->assertJsonPath('role', 'participant');
});

it('refuses someone who booked no seat', function (): void {
    $outsider = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $outsider);

    Sanctum::actingAs($outsider);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")
        ->assertForbidden()
        ->assertJsonPath('code', 'session_not_joinable');
});

it('refuses before the join window opens', function (): void {
    seatedStudent($this->student);

    $this->session->update([
        'starts_at' => CarbonImmutable::now()->addHours(3),
        'ends_at' => CarbonImmutable::now()->addHours(4),
    ]);

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")
        ->assertForbidden()
        ->assertJsonPath('code', 'session_not_joinable');
});

it('refuses after the join window closes', function (): void {
    seatedStudent($this->student);

    $this->session->update([
        'starts_at' => CarbonImmutable::now()->subHours(4),
        'ends_at' => CarbonImmutable::now()->subHours(3),
    ]);

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")
        ->assertForbidden();
});

// FR-015 — the ticket is not what is checked; the closed room is.
it('refuses every earlier ticket once the room is closed', function (): void {
    seatedStudent($this->student);

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();
    // host/end is the only early close. `/leave` was a second route to the same
    // Action, authorised the same way, and nothing called it.
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/end")->assertOk();

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")
        ->assertForbidden();
});

// The refusals must be indistinguishable from one another.
it('answers every refusal identically', function (): void {
    $outsider = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    Sanctum::actingAs($outsider);

    $noSeat = $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join");

    $this->session->update([
        'starts_at' => CarbonImmutable::now()->addHours(5),
        'ends_at' => CarbonImmutable::now()->addHours(6),
    ]);

    $wrongTime = $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join");

    expect($noSeat->json())->toEqual($wrongTime->json());
});
