<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-003 — and the whole evidence for the one deviation 017 accepts.
|
| FR-009 asks that closing the room stop every earlier ticket from being of any
| use to its holder. NO managed provider can promise that: LiveKit has no ticket
| revocation — no blacklist, no revoke — and its room is RE-CREATED automatically
| when the first participant joins, so a live ticket rebuilds a room we deleted.
| The one setting that prevents it (`room.auto_create: false`) is not exposed by
| the Cloud deployment Q7 chose.
|
| So the promise is kept by two guards of our own, and this file is both of them:
|
|   (أ) `room_closed_at` refuses to ISSUE another ticket — already covered by
|       RoomAccessTest, and asserted again here beside its twin, because the pair
|       is the requirement and either alone is not.
|   (ب) the first `presence` heartbeat after the close EVICTS whoever returned on
|       a ticket still inside its ten minutes — because BroadcastController runs
|       IssueJoinTicket again on every single ping.
|
| The residual exposure is therefore ≤ one heartbeat in a room that is EMPTY: the
| host has gone, the egress has stopped, the session is closed. What this test
| pins down is the part that matters — zero seconds credited to the register and
| zero effect on billing.
|
| ⚠️ A NEW FILE RATHER THAN A CASE IN RoomAccessTest, deliberately: T022 requires
| that file to stay green WITHOUT a line changed, which is how SC-001 stays
| measurable.
*/

beforeEach(function (): void {
    // A `->delay()` runs immediately on the sync connection, so CloseClassSessionJob
    // would shut the room before the timeline being tested even starts.
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
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->session, $this->student);
});

/** Opens the room as the host, then closes it — the only early close there is. */
function hostOpensThenEnds(): void
{
    Sanctum::actingAs(test()->owner);
    test()->postJson('/api/v1/class-sessions/'.test()->session->uuid.'/join')->assertOk();
    test()->postJson('/api/v1/class-sessions/'.test()->session->uuid.'/host/end')->assertOk();
}

it('evicts a returner on the first heartbeat after the room closes', function (): void {
    $uuid = $this->session->uuid;

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$uuid}/join")->assertOk();

    // The student is in the room and being counted, exactly as they should be.
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/class-sessions/{$uuid}/join")->assertOk();
    $this->postJson("/api/v1/class-sessions/{$uuid}/presence")->assertOk();

    $this->travel(31)->seconds();
    $accrued = (int) $this->postJson("/api/v1/class-sessions/{$uuid}/presence")
        ->assertOk()
        ->json('stay_seconds');

    expect($accrued)->toBeGreaterThan(0);

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$uuid}/host/end")->assertOk();

    // Guard (ب). The heartbeat is what discovers the close — enforcement is
    // instant on the server, but a client only finds out when it next speaks.
    $this->travel(31)->seconds();

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/class-sessions/{$uuid}/presence")
        ->assertForbidden()
        ->assertJsonPath('code', 'session_not_joinable');

    // And not one second of the time they spent in an empty room is credited.
    $attendance = Attendance::query()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->sole();

    expect((int) $attendance->stay_seconds)->toBe($accrued);
});

// Guard (أ), asserted beside its twin: the pair is the requirement.
it('issues no further ticket once the room is closed', function (): void {
    hostOpensThenEnds();

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")
        ->assertForbidden();
});
