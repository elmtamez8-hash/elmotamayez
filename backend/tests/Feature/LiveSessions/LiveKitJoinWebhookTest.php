<?php

declare(strict_types=1);

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\VideoGrant;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\JoinEnforcer;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Security scan 2026-10-10, F14 — a removed student pasted their ticket into
| another LiveKit client and walked back in; the heartbeat's 403 only makes OUR
| page leave. The join is now answered on LiveKit's `participant_joined`.
|
| No live LiveKit here: the admission decision is asked directly, and the HTTP
| door is asked about its signature and its constant answer.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class]);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);
    $course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
    $this->session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $course, $this->student);
    fundBooking($this->workspace, $this->student, $course);
    app(BookSeat::class)->handle($this->session, $this->student);
    app(OpenBroadcastRoom::class)->handle($this->session);

    // The student is inside, so their attendance row exists to be stamped.
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/presence")->assertOk();

    // What a webhook request looks like to the app: nobody signed in, no team.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function signedWebhook(string $body): string
{
    return (new AccessToken('test-key', str_repeat('s', 40)))
        ->setGrant(new VideoGrant)
        ->setSha256(base64_encode(hash('sha256', $body, true)))
        ->toJwt();
}

function joinedBody(string $room, string $identity, string $kind = 'STANDARD'): string
{
    return (string) json_encode([
        'event' => 'participant_joined',
        'room' => ['name' => $room],
        'participant' => ['identity' => $identity, 'kind' => $kind],
    ]);
}

it('admits the teacher who hosts the room, with no team id set', function (): void {
    expect(app(JoinEnforcer::class)->admits($this->session->fresh(), $this->owner->fresh()))->toBeTrue();
});

it('admits a seated student and turns away one the host removed', function (): void {
    $enforcer = app(JoinEnforcer::class);

    expect($enforcer->admits($this->session->fresh(), $this->student->fresh()))->toBeTrue();

    Attendance::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->update(['removed_at' => now()]);

    expect($enforcer->admits($this->session->fresh(), $this->student->fresh()))->toBeFalse();
});

it('refuses an unsigned webhook and answers a signed one with nothing', function (): void {
    // The room above was opened on the test provider; the webhook is LiveKit's.
    config([
        'sessions.provider' => 'livekit',
        'sessions.livekit.url' => 'wss://example.livekit.cloud',
        'sessions.livekit.key' => 'test-key',
        'sessions.livekit.secret' => str_repeat('s', 40),
    ]);

    $body = joinedBody("session-{$this->session->uuid}", (string) $this->student->uuid);

    $this->call('POST', '/api/v1/webhooks/broadcast', [], [], [], ['CONTENT_TYPE' => 'application/webhook+json'], $body)
        ->assertUnauthorized();

    $this->call('POST', '/api/v1/webhooks/broadcast', [], [], [], [
        'CONTENT_TYPE' => 'application/webhook+json',
        'HTTP_AUTHORIZATION' => signedWebhook($body),
    ], $body)->assertNoContent();

    // A signature over a DIFFERENT body is a forgery.
    $this->call('POST', '/api/v1/webhooks/broadcast', [], [], [], [
        'CONTENT_TYPE' => 'application/webhook+json',
        'HTTP_AUTHORIZATION' => signedWebhook(joinedBody('session-other', 'nobody')),
    ], $body)->assertUnauthorized();
});
