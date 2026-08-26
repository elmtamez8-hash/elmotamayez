<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Providers;

use App\Models\User;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\BroadcastCapabilities;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Data\RoomHandle;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Exceptions\UnsupportedCapability;
use App\Modules\LiveSessions\Models\ClassSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A room with a door and no media.
 *
 * It exists so the commercial provider choice can be made later without the code
 * waiting on it. It is not a pretend implementation of everything: it declares
 * liveMedia, recording and hostControls false, and refuses them loudly, so no
 * test can quietly pass on a capability nobody has.
 *
 * What it does implement is the whole part that matters most and does not need a
 * provider: the room's lifecycle and the door. Attendance never touches this
 * class at all (research §R3), which is why the register can be built and proved
 * against it.
 */
class NullBroadcastProvider implements BroadcastProviderInterface
{
    private const TICKET_TTL_MINUTES = 10;

    public function identifier(): string
    {
        return 'null';
    }

    public function capabilities(): BroadcastCapabilities
    {
        return new BroadcastCapabilities(
            // Would mean implementing WebRTC — half a broadcast provider, thrown
            // away the day a real one is signed.
            liveMedia: false,
            screenShare: false,
            recording: false,
            hostControls: false,
            maxParticipants: 100,
        );
    }

    public function createRoom(ClassSession $session): RoomHandle
    {
        // Idempotent: a session already carrying a room id keeps it, so a retried
        // job does not strand the first room.
        $roomId = $session->broadcast_room_id ?? 'null-room-'.Str::uuid()->toString();

        return new RoomHandle(
            providerRoomId: $roomId,
            joinBaseUrl: url('/sessions/'.$session->uuid.'/room'),
        );
    }

    public function issueTicket(ClassSession $session, User $user, ParticipantRole $role): JoinTicket
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(self::TICKET_TTL_MINUTES);

        // Encrypted rather than signed-and-readable: there is no provider to hand
        // a claim set to, and an opaque token cannot be edited into a different
        // role by whoever holds it.
        $token = Crypt::encryptString(json_encode([
            'session' => $session->uuid,
            'user' => $user->getKey(),
            'role' => $role->value,
            'expires' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return new JoinTicket(
            roomUrl: url('/sessions/'.$session->uuid.'/room'),
            token: $token,
            expiresAt: $expiresAt,
            role: $role->value,
        );
    }

    public function hostAction(ClassSession $session, HostAction $action, ?User $target = null, ?User $actor = null): void
    {
        throw UnsupportedCapability::for($this->identifier(), 'hostControls');
    }

    public function closeRoom(ClassSession $session): void
    {
        // Nothing to tear down. The refusal to re-enter is enforced by
        // room_closed_at on our side, not by the provider — which is exactly
        // where that decision belongs.
    }

    public function recording(ClassSession $session): ?RecordingArtifact
    {
        // Not "failed" — there is simply nothing recorded. The ingest job treats
        // null as "not yet" and stops retrying once the provider says it cannot
        // record at all.
        return null;
    }
}
