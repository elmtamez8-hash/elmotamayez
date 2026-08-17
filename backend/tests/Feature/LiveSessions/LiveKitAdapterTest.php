<?php

declare(strict_types=1);

use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\RoomServiceClient;
use App\Models\User;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Providers\LiveKitBroadcastProvider;
use App\Modules\LiveSessions\Support\SessionSettings;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Livekit\EgressInfo;
use Livekit\EgressStatus;
use Livekit\FileInfo;
use Livekit\ListEgressResponse;
use Livekit\ParticipantInfo;
use Livekit\RemoveParticipantResponse;
use Livekit\TrackInfo;
use Livekit\TrackType;

/*
| The adapter, opened up.
|
| Every assertion here runs with the service clients replaced, so the suite needs
| no LiveKit account, no key and no network (SC-006) — and the ticket is NOT
| stubbed, because minting one is local arithmetic and is precisely the thing
| worth checking. What travels in that JWT is the entire door.
*/

const ADAPTER_SECRET = 'adapter-test-secret-at-least-32-bytes';

beforeEach(function (): void {
    config()->set('sessions.livekit.url', 'wss://adapter.test');
    config()->set('sessions.livekit.key', 'adapter-key');
    config()->set('sessions.livekit.secret', ADAPTER_SECRET);
});

/**
 * A provider whose clients refuse every call.
 *
 * A Mockery mock with no expectations throws on any method reached, which makes
 * "this code path touches no client" an assertion rather than a comment.
 */
function adapter(): LiveKitBroadcastProvider
{
    return new LiveKitBroadcastProvider(
        new SessionSettings,
        Mockery::mock(RoomServiceClient::class),
        Mockery::mock(EgressServiceClient::class),
    );
}

function adapterSession(): ClassSession
{
    return ClassSession::factory()->makeOne([
        'workspace_id' => 1,
        'teacher_profile_id' => 1,
        'uuid' => (string) Str::orderedUuid(),
        'duration_minutes' => 60,
    ]);
}

function adapterUser(): User
{
    return User::factory()->makeOne([
        'id' => 11,
        'uuid' => (string) Str::orderedUuid(),
        'name' => 'سارة عبد الله',
    ]);
}

/** @return array<string, mixed> */
function decodedTicket(string $token): array
{
    return (array) JWT::decode($token, new Key(ADAPTER_SECRET, 'HS256'));
}

/*
| §R3 written as a gate.
|
| BroadcastController::presence() re-runs IssueJoinTicket on EVERY heartbeat —
| per participant, every 30 seconds. One provider call in issueTicket() and a
| LiveKit outage answers 403 to every ping, which marks the whole class absent.
| Without this test the temptation ("just ensure the room exists first") returns
| at the first refactor, and nothing would fail.
*/
it('mints a ticket without touching the provider at all', function (): void {
    $ticket = adapter()->issueTicket(adapterSession(), adapterUser(), ParticipantRole::Participant);

    expect($ticket->token)->not->toBe('');
});

it('grants join on exactly one room and nothing else', function (): void {
    $session = adapterSession();

    $claims = decodedTicket(
        adapter()->issueTicket($session, adapterUser(), ParticipantRole::Participant)->token
    );

    $video = (array) $claims['video'];

    expect($video['room'])->toBe('session-'.$session->uuid)
        ->and($video['roomJoin'])->toBeTrue()
        // Not a room creator, not a lister. The grant is the door, not a keyring.
        ->and($video['roomCreate'] ?? false)->toBeFalse()
        ->and($video['roomList'] ?? false)->toBeFalse();
});

// FR-006. The identity is echoed to every other participant in the room, so a
// name here would publish one student's name to the rest of the class.
it('identifies a participant by uuid, never by name', function (): void {
    $user = adapterUser();

    $claims = decodedTicket(
        adapter()->issueTicket(adapterSession(), $user, ParticipantRole::Participant)->token
    );

    expect($claims['sub'])->toBe($user->uuid)
        ->and(json_encode($claims, JSON_UNESCAPED_UNICODE))->not->toContain('سارة');
});

// FR-007 · research §R4. The library's own default ttl is SIX HOURS.
it('expires with the configured ttl and not the library default', function (): void {
    $ttl = app(SessionSettings::class)->ticketTtlMinutes();

    $claims = decodedTicket(
        adapter()->issueTicket(adapterSession(), adapterUser(), ParticipantRole::Host)->token
    );

    expect($claims['exp'] - $claims['iat'])->toBe($ttl * 60)
        ->and($claims['exp'] - $claims['iat'])->toBeLessThan(3600);
});

// FR-010. The role lives inside the signed ticket, so its holder cannot promote
// themselves by editing anything they can reach.
it('marks the host as room admin and the student as not', function (): void {
    $host = (array) decodedTicket(
        adapter()->issueTicket(adapterSession(), adapterUser(), ParticipantRole::Host)->token
    )['video'];

    $student = (array) decodedTicket(
        adapter()->issueTicket(adapterSession(), adapterUser(), ParticipantRole::Participant)->token
    )['video'];

    expect($host['roomAdmin'])->toBeTrue()
        ->and($student['roomAdmin'] ?? false)->toBeFalse()
        // Both may speak: a student raises her hand and talks (US1). Publishing
        // is not a host privilege here, and never was.
        ->and($student['canPublish'])->toBeTrue();
});

/*
| SC-005, with the distinction the criterion originally missed.
|
| The API KEY is in every ticket and must be: it is the JWT's `iss` claim, which
| is how the server knows which key to verify the signature against. It is an
| identifier, not a credential — it opens nothing on its own.
|
| The API SECRET is the credential. It SIGNS the ticket and must never travel
| with one, and that is what this asserts. A test that banned both would have
| been a test that cannot pass, and would have been "fixed" by deleting it.
*/
it('signs with the secret and never ships it', function (): void {
    $ticket = adapter()->issueTicket(adapterSession(), adapterUser(), ParticipantRole::Host);
    $claims = decodedTicket($ticket->token);

    $everything = json_encode([
        $ticket->roomUrl,
        $ticket->role,
        $claims,
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain(ADAPTER_SECRET)
        ->and($claims['iss'])->toBe('adapter-key');
});

/*
| Muting a PERSON, not a track.
|
| Two devices, or a headset beside a laptop microphone, publish two audio tracks.
| Muting the first and reporting success is the failure this product is most
| exposed to: the teacher presses the button, the noise continues, and nothing
| says why. Resolving the tracks inside the adapter is also what keeps `trackSid`
| out of the interface (research §R8).
*/
it('mutes every audio track a participant has published', function (): void {
    $session = adapterSession();
    $user = adapterUser();
    $room = 'session-'.$session->uuid;

    $participant = new ParticipantInfo([
        'tracks' => [
            new TrackInfo(['sid' => 'TR_mic', 'type' => TrackType::AUDIO]),
            new TrackInfo(['sid' => 'TR_headset', 'type' => TrackType::AUDIO]),
            new TrackInfo(['sid' => 'TR_cam', 'type' => TrackType::VIDEO]),
        ],
    ]);

    $rooms = Mockery::mock(RoomServiceClient::class);
    $rooms->shouldReceive('getParticipant')->once()->with($room, $user->uuid)->andReturn($participant);
    // Both microphones, and not the camera: muting someone must not blank them.
    $rooms->shouldReceive('mutePublishedTrack')->once()->with($room, $user->uuid, 'TR_mic', true);
    $rooms->shouldReceive('mutePublishedTrack')->once()->with($room, $user->uuid, 'TR_headset', true);

    $provider = new LiveKitBroadcastProvider(
        new SessionSettings,
        $rooms,
        Mockery::mock(EgressServiceClient::class),
    );

    $provider->hostAction($session, HostAction::Mute, $user);
});

it('removes a participant by uuid, never by name', function (): void {
    $session = adapterSession();
    $user = adapterUser();

    $rooms = Mockery::mock(RoomServiceClient::class);
    $rooms->shouldReceive('removeParticipant')
        ->once()
        ->with('session-'.$session->uuid, $user->uuid)
        ->andReturn(new RemoveParticipantResponse);

    $provider = new LiveKitBroadcastProvider(
        new SessionSettings,
        $rooms,
        Mockery::mock(EgressServiceClient::class),
    );

    $provider->hostAction($session, HostAction::Remove, $user);
});

/*
| Ending is ours, so the provider is not asked to do it.
|
| `PerformHostAction` turns End into `CloseBroadcastRoom` before this interface is
| reached, because `room_closed_at` is what refuses re-entry. A branch here would
| be a second way to close one room — and the clients below refuse every call, so
| this asserts that no second way exists rather than describing it in a comment.
*/
it('asks the provider for nothing when the action is end', function (): void {
    adapter()->hostAction(adapterSession(), HostAction::End);

    expect(true)->toBeTrue();
});

/*
| "Not finished yet" is the expected answer, and it is null — never an exception.
|
| The first ingest attempt after EVERY session hits one of these states. A
| provider that threw would turn each of them into a logged failure and burn an
| attempt against the limit (FR-013).
*/
it('answers null for every egress state short of complete', function (int $status): void {
    $egress = Mockery::mock(EgressServiceClient::class);
    $egress->shouldReceive('listEgress')->andReturn(
        new ListEgressResponse(['items' => [new EgressInfo(['status' => $status])]])
    );

    $provider = new LiveKitBroadcastProvider(
        new SessionSettings,
        Mockery::mock(RoomServiceClient::class),
        $egress,
    );

    expect($provider->recording(adapterSession()))->toBeNull();
})->with([
    'starting' => EgressStatus::EGRESS_STARTING,
    'active' => EgressStatus::EGRESS_ACTIVE,
    'ending' => EgressStatus::EGRESS_ENDING,
    'failed' => EgressStatus::EGRESS_FAILED,
    'aborted' => EgressStatus::EGRESS_ABORTED,
]);

/*
| FR-012, asserted rather than argued.
|
| The recording lands in a bucket WE own because that is where the egress was
| told to write, so `downloadUrl` is ours and there is no provider-hosted link in
| existence to leak. This pins that: the artifact carries the file's reported
| location, and the moment anyone substitutes a provider URL the shape changes
| here first.
*/
it('builds the artifact from the file in our own bucket', function (): void {
    $egress = Mockery::mock(EgressServiceClient::class);
    $egress->shouldReceive('listEgress')->andReturn(
        new ListEgressResponse(['items' => [new EgressInfo([
            'status' => EgressStatus::EGRESS_COMPLETE,
            'file_results' => [new FileInfo([
                'location' => 'https://r2.example.test/mteatch-recordings/session-x.mp4',
                'size' => 1_048_576,
                // The library reports nanoseconds. 3600s, not 3.6 billion.
                'duration' => 3_600_000_000_000,
            ])],
        ])]])
    );

    $provider = new LiveKitBroadcastProvider(
        new SessionSettings,
        Mockery::mock(RoomServiceClient::class),
        $egress,
    );

    $artifact = $provider->recording(adapterSession());

    expect($artifact)->not->toBeNull()
        ->and($artifact->downloadUrl)->toContain('r2.example.test')
        ->and($artifact->sizeBytes)->toBe(1_048_576)
        ->and($artifact->durationSeconds)->toBe(3600)
        ->and($artifact->mimeType)->toBe('video/mp4');
});

// The contract's idempotency, at the level below it: a session that already has
// a room must not cost a call, never mind a second room.
it('creates no room for a session that already has one', function (): void {
    $session = adapterSession();
    $session->broadcast_room_id = 'session-existing';

    expect(adapter()->createRoom($session)->providerRoomId)->toBe('session-existing');
});
