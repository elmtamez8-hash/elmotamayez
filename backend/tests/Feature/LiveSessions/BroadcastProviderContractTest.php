<?php

declare(strict_types=1);

use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\RoomServiceClient;
use App\Models\User;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Exceptions\UnsupportedCapability;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Providers\LiveKitBroadcastProvider;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Modules\LiveSessions\Support\SessionSettings;
use Livekit\DeleteRoomResponse;
use Livekit\ListEgressResponse;
use Tests\Support\FakeBroadcastProvider;

/*
| The gate that makes deferring the broadcast provider safe rather than
| optimistic.
|
| Every implementation runs the same set, and each is held to exactly what it
| CLAIMS — no more. A commercial provider added later is a file in Providers/ and
| a line in the dataset below; if it declares a capability it does not have, the
| build fails here rather than a teacher finding out mid-lesson that "mute" does
| nothing.
*/

dataset('broadcastProviders', [
    'null' => fn () => new NullBroadcastProvider,
    'fake' => fn () => new FakeBroadcastProvider,
    // The commercial adapter, held to the same set as the other two — with its
    // service clients injected, so the suite still needs no account and no
    // network (SC-006). Ticket minting is local arithmetic and is NOT stubbed:
    // the real AccessToken signs a real JWT here.
    'livekit' => function (): LiveKitBroadcastProvider {
        config()->set('sessions.livekit.url', 'wss://contract.test');
        config()->set('sessions.livekit.key', 'contract-key');
        // 32 bytes minimum: HS256 refuses a shorter key outright, which is also
        // why a half-filled .env fails loudly rather than signing something weak.
        config()->set('sessions.livekit.secret', 'contract-secret-not-a-real-one-32');

        $rooms = Mockery::mock(RoomServiceClient::class);
        $rooms->shouldReceive('deleteRoom')->andReturn(new DeleteRoomResponse);

        $egress = Mockery::mock(EgressServiceClient::class);
        $egress->shouldReceive('listEgress')->andReturn(new ListEgressResponse);

        return new LiveKitBroadcastProvider(new SessionSettings, $rooms, $egress);
    },
]);

/**
 * A participant with a uuid — because the uuid IS the identity a provider is
 * given (FR-006), never the person's name. `makeOne()` skips the model events
 * that fill it, so a fixture without it tested a null identity.
 */
function contractUser(): User
{
    return User::factory()->makeOne([
        'id' => 7,
        'uuid' => (string) Str::orderedUuid(),
    ]);
}

function contractSession(): ClassSession
{
    return ClassSession::factory()->makeOne([
        'workspace_id' => 1,
        'teacher_profile_id' => 1,
        'uuid' => (string) Str::orderedUuid(),
    ]);
}

it('names itself', function (BroadcastProviderInterface $provider): void {
    expect($provider->identifier())->not->toBe('');
})->with('broadcastProviders');

it('declares its capabilities', function (BroadcastProviderInterface $provider): void {
    $capabilities = $provider->capabilities();

    expect($capabilities->maxParticipants)->toBeGreaterThan(0);
})->with('broadcastProviders');

// Idempotent by contract: a retried job must not strand the first room and leave
// half the class in it.
it('creates the same room twice without making two', function (BroadcastProviderInterface $provider): void {
    $session = contractSession();
    $session->broadcast_room_id = 'existing-room';

    expect($provider->createRoom($session)->providerRoomId)->toBe('existing-room');
})->with('broadcastProviders');

// FR-019 · the ticket is handed to a browser, so anything secret in it is public.
// Scanned for shapes rather than exact keys: a provider that invents its own
// field name should still fail.
it('puts no credential in a join ticket', function (BroadcastProviderInterface $provider): void {
    $user = contractUser();
    $ticket = $provider->issueTicket(contractSession(), $user, ParticipantRole::Participant);

    $serialised = strtolower(json_encode([
        $ticket->roomUrl,
        $ticket->role,
    ], JSON_THROW_ON_ERROR));

    foreach (['api_key', 'apikey', 'secret', 'api-secret', 'private_key'] as $shape) {
        expect($serialised)->not->toContain($shape);
    }

    expect($ticket->token)->not->toBe('');
})->with('broadcastProviders');

it('issues a ticket that expires', function (BroadcastProviderInterface $provider): void {
    $user = contractUser();
    $ticket = $provider->issueTicket(contractSession(), $user, ParticipantRole::Host);

    // Measured against the SETTING, not a literal: the ttl is a platform_settings
    // row an operator tunes (FR-007), so a hard-coded ceiling would fail this gate
    // over a perfectly legitimate configuration change.
    //
    // The old assertion was "less than 24 hours" — and the LiveKit library's own
    // default ttl is SIX. A forgotten setTtl() would have shipped green with a
    // ticket that opens the room long after the lesson ended (research §R4).
    $allowed = app(SessionSettings::class)->ticketTtlMinutes() + 1;

    // Direction matters as much as the bound: the old form measured
    // now − expiresAt on a FUTURE date, so it was comparing a negative number
    // against 24 and would have passed for any ttl whatsoever.
    expect($ticket->expiresAt->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($ticket->expiresAt))->toBeLessThanOrEqual($allowed);
})->with('broadcastProviders');

/*
| The heart of the contract: a capability declared false must REFUSE, loudly.
|
| A provider that silently no-ops an unsupported host control is worse than one
| that has none — the teacher presses mute, nothing happens, and nothing says so.
*/
it('honours or refuses host controls according to what it claims', function (BroadcastProviderInterface $provider): void {
    $session = contractSession();

    if ($provider->capabilities()->hostControls) {
        $provider->hostAction($session, HostAction::End);

        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => $provider->hostAction($session, HostAction::End))
        ->toThrow(UnsupportedCapability::class);
})->with('broadcastProviders');

// "Not ready yet" is the expected answer on the first call after every session,
// not an error. A provider that throws here would turn each ingest attempt into
// a logged failure.
it('answers null rather than throwing when no recording is ready', function (BroadcastProviderInterface $provider): void {
    expect($provider->recording(contractSession()))->toBeNull();
})->with('broadcastProviders');

it('closes a room twice without complaining', function (BroadcastProviderInterface $provider): void {
    $session = contractSession();

    $provider->closeRoom($session);
    $provider->closeRoom($session);

    expect(true)->toBeTrue();
})->with('broadcastProviders');
