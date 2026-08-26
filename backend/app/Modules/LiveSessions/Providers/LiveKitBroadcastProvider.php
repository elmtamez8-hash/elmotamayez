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
use App\Modules\LiveSessions\Exceptions\BroadcastProviderUnavailable;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Livekit\EgressInfo;
use Livekit\EgressStatus;
use Livekit\EncodedFileOutput;
use Livekit\ParticipantInfo;
use Livekit\ParticipantInfo\Kind;
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
    /**
     * The only participant attributes this product writes: «ارفع يدك» and
     * «لم أفهم».
     *
     * ⚠️ THE SAME KEYS ARE READ IN THE BROWSER (`BroadcastStage` ·
     * `ParticipantsPanel`) and there is no way to share a constant across that
     * boundary — so they are named here once, and a rename is a change in three
     * files that no compiler will pair up. Anything ELSE that turns up in a
     * participant's attributes is somebody's keyboard: they are written by the
     * client that owns them, which is what makes a hand go up with no round trip
     * and exactly why nothing may be rendered from them as text.
     */
    private const SIGNAL_ATTRIBUTES = ['hand', 'confused'];

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

            /*
             * ⚠️ THE PROVIDER BEING DOWN IS AN ANSWER, AND IT USED TO BE A 500.
             *
             * `TwirpError` escaped this call uncaught — a transport failure as
             * much as a protocol one — so the teacher's screen carried
             * «cURL error 6: Could not resolve host …» naming our internal host,
             * and in production, with `APP_DEBUG` off, carried nothing at all.
             * The quickstart calls this step the most important and the least
             * noticed, for the reason it is: a provider outage is the one failure
             * nobody is accountable for, so nobody checks that it is SAID.
             *
             * Translated here because this is the one file allowed to know the
             * vendor's name (`FR-002`), and into a type of its own rather than
             * the module's uniform refusal — see BroadcastProviderUnavailable for
             * why an outage may be named while an entitlement may not.
             */
            try {
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
            } catch (TwirpError $e) {
                throw new BroadcastProviderUnavailable(
                    'تعذّر فتحُ غرفةِ البثّ الآن — خدمةُ البثِّ لا تستجيب. لم يُحتسَب على أحدٍ شيء، وأعِد المحاولةَ بعد قليل.',
                    previous: $e,
                );
            }
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
            /*
             * A raised hand, and nothing else, is what this permits.
             *
             * Attributes are synced by the provider to everyone in the room and
             * REPLAYED to whoever joins later, which a data message is not — so a
             * hand raised before the teacher opened their laptop is still up when
             * they arrive. That is the whole reason it is an attribute.
             *
             * ⚠️ AND IT IS CLIENT-WRITTEN BY DEFINITION, so nothing may render it
             * as text. The screen reads one key as a boolean and draws an icon; a
             * participant who writes a sentence into their own attributes has
             * written it to a value nobody prints. The role is NOT here — it
             * lives in the signed grant above, where its holder cannot reach it.
             */
            ->setCanUpdateOwnMetadata(true)
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

    /** @return list<string> */
    public function hostAction(ClassSession $session, HostAction $action, ?User $target = null, ?User $actor = null): array
    {
        // ⚠️ ENDING IS OURS, AND THERE IS DELIBERATELY NO BRANCH FOR IT.
        //
        // PerformHostAction turns End into CloseBroadcastRoom before it ever
        // reaches this interface, because `room_closed_at` is what refuses
        // re-entry — not the provider. A branch here would be a second way to
        // close one room, and the two would drift the first time either changed.
        if ($action === HostAction::End) {
            return [];
        }

        $room = $this->roomName($session);

        if ($action->isBulk()) {
            return $this->applyToWholeRoom($room, $action, $actor?->uuid);
        }

        if ($target === null) {
            return [];
        }

        $identity = $target->uuid;

        /*
         * ⚠️ «THEY ARE NOT IN THE ROOM» IS AN ANSWER, NOT A CRASH — AND IT WAS A 500.
         *
         * A teacher presses mute on the participant list; the student closed
         * their laptop a second earlier. LiveKit answers `TwirpError: participant
         * does not exist`, which nothing caught: `BroadcastController::host()`
         * handles `UnsupportedCapability` and `DomainException` and neither is
         * this, so the teacher read «حدث خطأ» — with `APP_DEBUG` on, a stack
         * trace — over the most ordinary event in a live lesson. Measured against
         * a real LiveKit project on 2026-08-26 (`T051` step ٧); no test could see
         * it, because a fake provider has no participants to be missing.
         *
         * It is translated HERE and not in the controller: this is the one file
         * that may know the provider's name (FR-002), and a `TwirpError` caught
         * upstairs would put the vendor's vocabulary in a controller. NotFound
         * alone becomes a sentence — every other code is still a real failure and
         * must keep travelling, exactly as `closeRoom()` decided for itself.
         */
        try {
            match ($action) {
                HostAction::Mute => $this->muteEveryAudioTrack($room, $identity),
                HostAction::Remove => $this->rooms()->removeParticipant($room, $identity),
                // Unreachable: `End` and every bulk form returned above. The arm
                // exists so a seventh action added tomorrow is a no-op here
                // rather than an UnhandledMatchError in the middle of a lesson.
                default => null,
            };
        } catch (TwirpError $e) {
            if ($e->getErrorCode() !== ErrorCode::NotFound) {
                throw $e;
            }

            throw new DomainException('هذا المشارك لم يعد في الغرفة.');
        }

        return [$identity];
    }

    /**
     * One press, the whole room.
     *
     * ⚠️ THE RECORDER IS A PARTICIPANT, AND «إخراج الجميع» WOULD HAVE EVICTED IT.
     * The egress joins the room as a participant of its own — `EG_…`, seen in the
     * live participant list on 2026-08-26 — so a loop over everyone removes the
     * recording mid-lesson and mutes a track that belongs to a robot. Only
     * `STANDARD` participants are people; anything else is infrastructure and is
     * skipped. Nothing in a fake provider could have shown this.
     *
     * ⚠️ AND SOMEBODY LEAVING MID-LOOP MUST NOT ABORT THE REST. The list is a
     * snapshot; by the time the fourth student is reached the second may have
     * closed their laptop, and `NotFound` there means the action already
     * happened. It is skipped per participant rather than raised, because the
     * teacher asked about the ROOM, not about that person — the single-target
     * form still answers «هذا المشارك لم يعد في الغرفة», where it is the answer.
     *
     * @return list<string> the identities actually acted on — what the caller
     *                      stamps, so a removal outlives the disconnect
     */
    private function applyToWholeRoom(string $room, HostAction $action, ?string $exceptIdentity): array
    {
        $touched = [];

        foreach ($this->rooms()->listParticipants($room)->getParticipants() as $participant) {
            if (! $participant instanceof ParticipantInfo || $participant->getKind() !== Kind::STANDARD) {
                continue;
            }

            $identity = (string) $participant->getIdentity();

            if ($identity === $exceptIdentity) {
                continue;
            }

            try {
                match ($action) {
                    HostAction::MuteAll => $this->muteEveryAudioTrack($room, $identity),
                    HostAction::RemoveAll => $this->rooms()->removeParticipant($room, $identity),
                    HostAction::LowerHands => $this->clearSignals($room, $participant),
                    default => null,
                };
                $touched[] = $identity;
            } catch (TwirpError $e) {
                if ($e->getErrorCode() !== ErrorCode::NotFound) {
                    throw $e;
                }
            }
        }

        return $touched;
    }

    /**
     * Clear one person's raised hand and «لم أفهم», if either is set.
     *
     * ⚠️ ONE BUTTON FOR BOTH, WHICH IS WHY IT READS «امسح الإشارات». A teacher
     * who has just re-explained the point wants the room quiet again; clearing
     * the hands and leaving four «لم أفهم» behind would make the count on their
     * screen a number about a moment that has passed.
     *
     * An empty value DELETES the attribute rather than storing `""` — the SDK
     * says so, and the student's own control writes the same empty string, so
     * «lowered» has one spelling. Skipped when neither key is set, so clearing a
     * room with two hands up costs two calls and not twenty.
     */
    private function clearSignals(string $room, ParticipantInfo $participant): void
    {
        $attributes = $participant->getAttributes();
        $clear = [];

        foreach (self::SIGNAL_ATTRIBUTES as $key) {
            if (($attributes[$key] ?? '') !== '') {
                $clear[$key] = '';
            }
        }

        if ($clear === []) {
            return;
        }

        $this->rooms()->updateParticipant($room, $participant->getIdentity(), attributes: $clear);
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

        /*
         * ⚠️ THE SERVER FILLS THE DEPRECATED SINGULAR FIELD, AND READING ONLY THE
         * PLURAL ONE LOSES EVERY RECORDING SILENTLY.
         *
         * We request `file_outputs`, so `file_results` is the field that should
         * come back — and against LiveKit Cloud on 2026-08-18 it came back ABSENT,
         * with the whole result under the deprecated `file` instead:
         *
         *     "status":"EGRESS_COMPLETE","file":{"size":"15564521",
         *      "location":"https://….r2.cloudflarestorage.com/…/session-….mp4"}
         *
         * An empty `file_results` on a COMPLETE egress reads exactly like "not
         * finished yet", so the sweep would have re-asked until the attempt limit,
         * marked the session failed, and released the teacher's held fee against a
         * file sitting intact in our own bucket. Nothing would have been logged.
         *
         * Both are read, plural first: `file` is deprecated and will disappear,
         * `file_results` is what a newer server sends. The library announces the
         * deprecation on every call already — it fires while PARSING the response,
         * before we touch any accessor — so reaching for it adds noise we cannot
         * avoid and no risk we do not already carry.
         */
        $file = $completed->getFileResults()[0] ?? $completed->getFile();

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
                /*
                 * ⚠️ LEFT UNSET, THE RECORDING WASTES MOST OF ITS OWN FRAME.
                 *
                 * The default template starts on `grid` and switches itself to
                 * `speaker` the moment anyone shares a screen — which reserves a
                 * participant sidebar beside the shared content. With the camera
                 * off, that sidebar is empty: the first real recording (2026-08-18)
                 * put a shared screen in the left two-thirds of a 1280×720 frame,
                 * letterboxed, with black filling the rest. On a lesson whose whole
                 * value is readable text, that is most of the resolution spent on
                 * nothing.
                 *
                 * `single-speaker` renders the dominant track alone, full frame —
                 * which for a shared screen is the screen, edge to edge. It also
                 * keeps every OTHER participant's camera out of a file that is then
                 * published to everyone who booked the session: a grid would put a
                 * student's bedroom into a permanent lesson recording, which is a
                 * privacy decision nobody made on purpose.
                 */
                ->setLayout('single-speaker')
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

    /**
     * The same host, over the scheme the SERVER API speaks.
     *
     * ⚠️ ONE CONFIGURED URL SERVES TWO PROTOCOLS, AND HANDING THE RAW VALUE TO
     * THE SERVER CLIENTS BREAKS EVERY ROOM.
     *
     * `LIVEKIT_URL` is `wss://…` because that is what the BROWSER dials, and
     * `issueTicket` sends it on untouched. But `RoomServiceClient` and
     * `EgressServiceClient` speak Twirp over HTTP, and the transport refuses
     * anything else outright: `TwirpError: failed to send request: The scheme
     * 'wss' is not supported`. That surfaced as «تعذّر الدخول» on the room page,
     * with nothing in it naming a URL — the first real room ever opened against
     * a live LiveKit failed, and no test saw it because every test injects a
     * client and never reaches this line.
     *
     * Deriving it rather than adding a second env var: two URLs for one host is
     * two things to keep in step, and the day they disagree the tickets point
     * somewhere the server never provisioned.
     */
    private function apiUrl(): string
    {
        $url = (string) config('sessions.livekit.url');

        return str_starts_with($url, 'ws')
            ? 'http'.substr($url, 2)
            : $url;
    }

    private function rooms(): RoomServiceClient
    {
        // Built lazily so constructing the adapter — which the contract test and
        // the container both do — never reaches for a credential or a socket.
        return $this->rooms ??= new RoomServiceClient(
            $this->apiUrl(),
            (string) config('sessions.livekit.key'),
            (string) config('sessions.livekit.secret'),
        );
    }

    private function egress(): EgressServiceClient
    {
        return $this->egress ??= new EgressServiceClient(
            $this->apiUrl(),
            (string) config('sessions.livekit.key'),
            (string) config('sessions.livekit.secret'),
        );
    }
}
