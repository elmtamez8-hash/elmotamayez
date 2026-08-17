<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Providers;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Models\User;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\BroadcastCapabilities;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Data\RoomHandle;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use Carbon\CarbonImmutable;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Livekit\EgressInfo;
use Livekit\EgressStatus;
use Livekit\EncodedFileOutput;
use Livekit\RoomCompositeEgressRequest;
use Livekit\RoomEgress;
use Livekit\S3Upload;
use Livekit\TrackInfo;
use Livekit\TrackType;
use Twirp\Error as TwirpError;
use Twirp\ErrorCode;

/**
 * The one file in the codebase that knows the provider's name (017 FR-002).
 *
 * A thin adapter, deliberately: the library signs the ticket, speaks protobuf
 * and holds the room. What stays ours is everything it cannot know — who may
 * sit, which seat was billed, and how long anyone actually stayed. Attendance
 * never passes through this class at all, exactly as it never passed through
 * NullBroadcastProvider (research §R3).
 *
 * Capabilities are declared false here and flipped one story at a time, each in
 * the same commit that implements the method behind it. The contract test holds
 * a provider to what it claims, so the adapter is honest about itself at every
 * point in its construction rather than only at the end.
 */
final class LiveKitBroadcastProvider implements BroadcastProviderInterface
{
    public function __construct(
        private readonly SessionSettings $settings,
        private ?RoomServiceClient $rooms = null,
        private ?EgressServiceClient $egress = null,
    ) {}

    public function identifier(): string
    {
        return 'livekit';
    }

    public function capabilities(): BroadcastCapabilities
    {
        return new BroadcastCapabilities(
            // US1: the room carries audio and video, and a participant may share
            // a screen. Flipped in the same change that implements them.
            liveMedia: true,
            screenShare: true,
            // US3: the room records itself into our own bucket.
            recording: true,
            // US2: mute and remove reach the provider; ending never does.
            hostControls: true,
            maxParticipants: $this->settings->maxParticipants(),
        );
    }

    public function createRoom(ClassSession $session): RoomHandle
    {
        $name = $this->roomName($session);

        // Idempotent, and cheaper than idempotent: a session already carrying a
        // room keeps it without a call at all, so a retried job cannot strand
        // the room half the class is already sitting in.
        if ($session->broadcast_room_id === null) {
            $lifetime = $this->roomLifetimeSeconds($session);

            $this->rooms()->createRoom(
                (new RoomCreateOptions)
                    ->setName($name)
                    // FR-003: the ceiling is PASSED to the provider, not merely
                    // declared to our own callers.
                    ->setMaxParticipants($this->settings->maxParticipants())
                    // Both timeouts are derived from this session's length. A
                    // constant would close a three-hour lesson's room in the
                    // middle of it (research §R5).
                    ->setEmptyTimeout($lifetime)
                    ->setDepartureTimeout($lifetime)
                    ->setEgress($this->automaticEgress($name))
            );
        }

        return new RoomHandle(
            providerRoomId: $session->broadcast_room_id ?? $name,
            joinBaseUrl: (string) config('sessions.livekit.url'),
        );
    }

    /**
     * Mint a ticket. Purely local arithmetic — NO network call. Ever.
     *
     * BroadcastController::presence() re-runs IssueJoinTicket on every heartbeat,
     * per participant, every 30 seconds. One provider call in here and a LiveKit
     * outage answers 403 to every ping — which marks the entire class absent
     * (research §R3 · FR-015). The room does not need to exist for a token to be
     * valid, so there is nothing to check anyway.
     */
    public function issueTicket(ClassSession $session, User $user, ParticipantRole $role): JoinTicket
    {
        $ttlMinutes = $this->settings->ticketTtlMinutes();
        $expiresAt = CarbonImmutable::now()->addMinutes($ttlMinutes);

        $grant = (new VideoGrant)
            ->setRoomJoin()
            // This room and no other. The grant is the whole door.
            ->setRoomName($this->roomName($session))
            // A student raises her hand and speaks — US1, not a host privilege.
            ->setCanPublish(true)
            ->setCanSubscribe(true)
            // FR-010: the role lives INSIDE the ticket, so its holder cannot
            // promote themselves by editing anything they can reach.
            ->setRoomAdmin($role === ParticipantRole::Host);

        $token = (new AccessToken(
            (string) config('sessions.livekit.key'),
            (string) config('sessions.livekit.secret'),
            (new AccessTokenOptions)
                // FR-006: the uuid, never the person's name. The identity is
                // echoed to every other participant in the room.
                ->setIdentity($user->uuid)
                // FR-007. The library's own default is SIX HOURS: a forgotten
                // setTtl() ships green and opens the room long after the lesson.
                ->setTtl($ttlMinutes * 60)
        ))->setGrant($grant)->toJwt();

        return new JoinTicket(
            roomUrl: (string) config('sessions.livekit.url'),
            token: $token,
            expiresAt: $expiresAt,
            role: $role->value,
        );
    }

    public function hostAction(ClassSession $session, HostAction $action, ?User $target = null): void
    {
        // ⚠️ ENDING IS OURS, AND THERE IS DELIBERATELY NO BRANCH FOR IT.
        //
        // PerformHostAction turns End into CloseBroadcastRoom before it ever
        // reaches this interface, because `room_closed_at` is what refuses
        // re-entry — not the provider. A branch here would be a second way to
        // close one room, and the two would drift the first time either changed.
        if ($action === HostAction::End || $target === null) {
            return;
        }

        $room = $this->roomName($session);
        $identity = $target->uuid;

        match ($action) {
            HostAction::Mute => $this->muteEveryAudioTrack($room, $identity),
            HostAction::Remove => $this->rooms()->removeParticipant($room, $identity),
        };
    }

    /**
     * Mute every audio track the participant has published, not the first.
     *
     * ⚠️ AND THE TRACK ID IS RESOLVED IN HERE. Widening `hostAction()` to take a
     * `trackSid` would put the provider's vocabulary into the interface that
     * exists to keep it out (research §R8) — a teacher pressing "mute" knows
     * about a person, not about a track. Someone joining from two devices, or
     * with a second microphone, is exactly the case a single track would miss.
     */
    private function muteEveryAudioTrack(string $room, string $identity): void
    {
        foreach ($this->rooms()->getParticipant($room, $identity)->getTracks() as $track) {
            if ($track instanceof TrackInfo && $track->getType() === TrackType::AUDIO) {
                $this->rooms()->mutePublishedTrack($room, $identity, $track->getSid(), true);
            }
        }
    }

    public function closeRoom(ClassSession $session): void
    {
        try {
            // Disconnects everyone still in it and stops the egress with it.
            $this->rooms()->deleteRoom($this->roomName($session));
        } catch (TwirpError $e) {
            // Only "there is no such room" is swallowed — closing twice must be
            // free. Swallowing everything would turn a provider outage into a
            // session that looks cleanly closed and never was.
            if ($e->getErrorCode() !== ErrorCode::NotFound) {
                throw $e;
            }
        }
    }

    public function recording(ClassSession $session): ?RecordingArtifact
    {
        $completed = $this->completedEgress($session);

        if ($completed === null) {
            return null;
        }

        $file = $completed->getFileResults()[0] ?? null;

        if ($file === null) {
            return null;
        }

        return new RecordingArtifact(
            // Points at OUR bucket, which is what makes FR-012 trivial rather
            // than a rule someone has to remember: there is no provider-hosted
            // link in existence to leak.
            downloadUrl: (string) $file->getLocation(),
            sizeBytes: (int) $file->getSize(),
            // The library reports nanoseconds.
            durationSeconds: (int) round(((int) $file->getDuration()) / 1_000_000_000),
            mimeType: 'video/mp4',
        );
    }

    private function completedEgress(ClassSession $session): ?EgressInfo
    {
        foreach ($this->egress()->listEgress($this->roomName($session))->getItems() as $item) {
            // Anything else — starting, active, ending, failed — is "not yet",
            // answered with null rather than an exception (FR-013). "Not
            // finished" is the expected reply to the first call after every
            // single session.
            if ($item instanceof EgressInfo && $item->getStatus() === EgressStatus::EGRESS_COMPLETE) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Recording, attached to the room itself rather than started by hand.
     *
     * ⚠️ THIS IS WHY THERE IS NO `egress_id` COLUMN AND NO START/STOP CALL.
     * Declaring the egress on the room means it begins when the room does and
     * ends when `deleteRoom` does — one lifecycle, not two that can disagree. A
     * manual `startEgress` needs its id stored, its failure handled, and its
     * `stopEgress` guaranteed to run, and the day one of those is missed is the
     * day a lesson records nothing and nobody finds out until a student asks.
     *
     * The destination is an S3-compatible bucket WE own (Cloudflare R2), and the
     * provider carries the write credential. FR-012 then costs nothing: no
     * provider-hosted link exists to leak, because the file was never theirs.
     */
    private function automaticEgress(string $roomName): RoomEgress
    {
        /** @var array<string, string|null> $bucket */
        $bucket = (array) config('sessions.livekit.egress');

        $file = (new EncodedFileOutput)
            // Named after the room, so the ingest job finds it without a lookup
            // table and a re-run overwrites rather than multiplying files.
            ->setFilepath($roomName.'.mp4')
            ->setS3(
                (new S3Upload)
                    ->setBucket((string) ($bucket['bucket'] ?? ''))
                    ->setEndpoint((string) ($bucket['endpoint'] ?? ''))
                    ->setRegion((string) ($bucket['region'] ?? ''))
                    ->setAccessKey((string) ($bucket['key'] ?? ''))
                    ->setSecret((string) ($bucket['secret'] ?? ''))
                    // R2 addresses buckets by path, not by subdomain.
                    ->setForcePathStyle(true)
            );

        // `file_outputs`, not the singular `file`: the latter is deprecated in
        // the proto and emits a deprecation on every room created.
        $outputs = new RepeatedField(GPBType::MESSAGE, EncodedFileOutput::class);
        $outputs[] = $file;

        return (new RoomEgress)->setRoom(
            (new RoomCompositeEgressRequest)
                ->setRoomName($roomName)
                ->setFileOutputs($outputs)
        );
    }

    /** Deterministic, so createRoom needs no stored state to be idempotent. */
    private function roomName(ClassSession $session): string
    {
        return 'session-'.$session->uuid;
    }

    /** Join window, the lesson itself, and the join window again. */
    private function roomLifetimeSeconds(ClassSession $session): int
    {
        return ($session->duration_minutes + (2 * $this->settings->joinWindowMinutes())) * 60;
    }

    private function rooms(): RoomServiceClient
    {
        // Built lazily so constructing the adapter — which the contract test and
        // the container both do — never reaches for a credential or a socket.
        return $this->rooms ??= new RoomServiceClient(
            (string) config('sessions.livekit.url'),
            (string) config('sessions.livekit.key'),
            (string) config('sessions.livekit.secret'),
        );
    }

    private function egress(): EgressServiceClient
    {
        return $this->egress ??= new EgressServiceClient(
            (string) config('sessions.livekit.url'),
            (string) config('sessions.livekit.key'),
            (string) config('sessions.livekit.secret'),
        );
    }
}
