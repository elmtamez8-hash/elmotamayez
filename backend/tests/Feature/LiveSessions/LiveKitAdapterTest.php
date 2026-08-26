<?php

declare(strict_types=1);

use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\RoomServiceClient;
use App\Models\User;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Exceptions\BroadcastProviderUnavailable;
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
use Livekit\TwirpError;
use Twirp\ErrorCode;

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

/*
| ⚠️ ONE CONFIGURED URL, TWO PROTOCOLS — AND THE SERVER HALF WAS NEVER CONVERTED.
|
| `LIVEKIT_URL` is `wss://…` because the BROWSER dials it, and the ticket carries
| it on untouched. The server clients speak Twirp over HTTP and refuse anything
| else at the transport: `TwirpError: failed to send request: The scheme 'wss' is
| not supported`. So the first room ever opened against a real LiveKit failed,
| and the student read «تعذّر الدخول» over a message naming no URL at all.
|
| Every other test in this file injects both clients, which is exactly why none
| of them reached the line that builds one. This asserts the conversion itself,
| on both sides: the ticket must KEEP the socket scheme while the API gets HTTP —
| a fix that changed the ticket instead would point the browser at a URL LiveKit
| does not accept, and the suite would still be green.
*/
it('speaks HTTP to the server API while the ticket keeps the socket scheme', function (): void {
    $apiUrl = (new ReflectionMethod(LiveKitBroadcastProvider::class, 'apiUrl'))
        ->getClosure(adapter());

    config()->set('sessions.livekit.url', 'wss://x.livekit.cloud');
    expect($apiUrl())->toBe('https://x.livekit.cloud');

    config()->set('sessions.livekit.url', 'ws://localhost:7880');
    expect($apiUrl())->toBe('http://localhost:7880');

    // A deployment already configured with the HTTP form must pass through, or
    // the conversion becomes a second way to break a working install.
    config()->set('sessions.livekit.url', 'https://x.livekit.cloud');
    expect($apiUrl())->toBe('https://x.livekit.cloud');

    config()->set('sessions.livekit.url', 'wss://x.livekit.cloud');
    expect(adapter()->issueTicket(adapterSession(), adapterUser(), ParticipantRole::Host)->roomUrl)
        ->toBe('wss://x.livekit.cloud');
});

/*
| ⚠️ AND THE SERVER MAY PUT IT IN THE DEPRECATED FIELD INSTEAD.
|
| The test above hands back `file_results`, which is what our own request asks
| for. LiveKit Cloud answered a real COMPLETE egress on 2026-08-18 with that field
| ABSENT and the whole result under the singular `file` — 15.5 MB of a lesson that
| had just been taught.
|
| An empty `file_results` on a COMPLETE egress is indistinguishable from "still
| encoding", so the sweep would have retried to the attempt limit, marked the
| session failed and released the teacher's held fee — against a file sitting
| intact in our own bucket, with nothing logged. This is the fixture the live
| server actually sent.
*/
it('reads a recording the server reported under the deprecated field', function (): void {
    $egress = Mockery::mock(EgressServiceClient::class);
    $egress->shouldReceive('listEgress')->andReturn(
        new ListEgressResponse(['items' => [new EgressInfo([
            'status' => EgressStatus::EGRESS_COMPLETE,
            // No `file_results` at all — exactly as it came back.
            'file' => new FileInfo([
                'location' => 'https://r2.example.test/mteatch-recordings/session-x.mp4',
                'size' => 15_564_521,
                'duration' => 133_000_384_487,
            ]),
        ])]])
    );

    $artifact = (new LiveKitBroadcastProvider(
        new SessionSettings,
        Mockery::mock(RoomServiceClient::class),
        $egress,
    ))->recording(adapterSession());

    expect($artifact)->not->toBeNull()
        ->and($artifact->sizeBytes)->toBe(15_564_521)
        ->and($artifact->durationSeconds)->toBe(133);
});

/*
| ⚠️ THE RECORDING'S LAYOUT IS A DECISION, AND LEAVING IT UNSET MAKES IT SOMEBODY
| ELSE'S.
|
| The default template reserves a participant sidebar as soon as a screen is
| shared. With the camera off that sidebar is empty, so the first real recording
| (2026-08-18) spent most of a 1280×720 frame on black beside a letterboxed
| screen share — on a lesson whose value is readable text.
|
| `single-speaker` renders the dominant track alone, edge to edge. It is also
| what keeps other participants' cameras out of a file published to everyone who
| booked the session.
*/
it('records the dominant track alone, filling the frame', function (): void {
    $rooms = Mockery::mock(RoomServiceClient::class);

    $captured = null;
    $rooms->shouldReceive('createRoom')->once()->with(Mockery::capture($captured));

    (new LiveKitBroadcastProvider(
        new SessionSettings,
        $rooms,
        Mockery::mock(EgressServiceClient::class),
    ))->createRoom(adapterSession());

    expect($captured)->not->toBeNull();

    $composite = $captured->getEgress()->getRoom();

    expect($composite->getLayout())->toBe('single-speaker')
        // And the file output is still the one we asked for, in our own bucket.
        ->and(count($composite->getFileOutputs()))->toBe(1);
});

/*
| The provider being down, and the provider saying "they left" — two answers that
| were both a 500 until the first live run (`T051`, 2026-08-26).
|
| Neither is reachable from a fake provider: one needs a transport failure and the
| other needs a participant who is not there, and a stub has neither. Both are
| translated inside the adapter because it is the one file allowed to know the
| vendor's name (FR-002) — a `TwirpError` caught in a controller would carry that
| vocabulary upstairs.
*/
it('turns an unreachable provider into a named outage, not a crash', function (): void {
    $session = adapterSession();

    $rooms = Mockery::mock(RoomServiceClient::class);
    $rooms->shouldReceive('createRoom')
        ->once()
        ->andThrow(TwirpError::newError(ErrorCode::Unavailable, 'failed to send request'));

    $provider = new LiveKitBroadcastProvider(
        new SessionSettings,
        $rooms,
        Mockery::mock(EgressServiceClient::class),
    );

    // The type matters as much as the message: BroadcastController answers it 503
    // ABOVE the uniform RuntimeException arm, which would otherwise tell a teacher
    // whose provider is down to go and check their booking.
    expect(fn () => $provider->createRoom($session))
        ->toThrow(BroadcastProviderUnavailable::class);
});

it('turns "that participant is gone" into a sentence, not a crash', function (): void {
    $session = adapterSession();
    $user = adapterUser();

    $rooms = Mockery::mock(RoomServiceClient::class);
    $rooms->shouldReceive('getParticipant')
        ->once()
        ->andThrow(TwirpError::newError(ErrorCode::NotFound, 'participant does not exist'));

    $provider = new LiveKitBroadcastProvider(
        new SessionSettings,
        $rooms,
        Mockery::mock(EgressServiceClient::class),
    );

    // A teacher pressing mute on somebody who closed their laptop a second
    // earlier: the most ordinary event in a live lesson, and it read «حدث خطأ».
    // DomainException is what the controller already maps to 422.
    expect(fn () => $provider->hostAction($session, HostAction::Mute, $user))
        ->toThrow(DomainException::class);
});
