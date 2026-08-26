<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\BroadcastCapabilities;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Data\RoomHandle;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Models\ClassSession;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * A provider that claims everything and does it in memory.
 *
 * NFR-009 forbids a real network call in the suite, so a fake has to exist
 * whatever provider is eventually signed. Declaring every capability true is
 * what makes user story 4 — the recording becoming a lesson — testable end to
 * end before any contract exists.
 *
 * What that does NOT prove is written down rather than assumed: see the
 * Deferred Verification table in the feature plan. "Tested against a fake" and
 * "proved in production" are different claims, and a green gate must not be read
 * as the second one.
 */
class FakeBroadcastProvider implements BroadcastProviderInterface
{
    /** @var list<array{action: string, target: int|null}> */
    public array $hostActions = [];

    public bool $roomClosed = false;

    /** Set by a test that wants the recording to be ready. */
    public ?RecordingArtifact $pendingRecording = null;

    /**
     * Whether this provider records at all.
     *
     * A real one declares it once and for ever; a fake needs to be BOTH, because
     * the sweep now asks the question before deciding whether a session that was
     * never written to is worth re-dispatching.
     */
    public bool $records = true;

    /**
     * An outage to raise from `recording()`.
     *
     * The real one reaches the network there, so "the provider is unreachable" is
     * a state the ingest job has to survive — and it did not, because the call
     * stood outside the `try`.
     */
    public ?Throwable $recordingError = null;

    /**
     * Run at the moment `recording()` is asked.
     *
     * The one seam a single-threaded test has for the interleaving that matters:
     * the ingest job reads `media_asset_id` BEFORE this call and claims it AFTER,
     * so a callback here is another runner winning inside that window.
     */
    public ?Closure $onRecording = null;

    public function identifier(): string
    {
        return 'fake';
    }

    public function capabilities(): BroadcastCapabilities
    {
        return new BroadcastCapabilities(
            liveMedia: true,
            screenShare: true,
            recording: $this->records,
            hostControls: true,
            maxParticipants: 50,
        );
    }

    public function createRoom(ClassSession $session): RoomHandle
    {
        return new RoomHandle(
            providerRoomId: $session->broadcast_room_id ?? 'fake-room-'.$session->getKey(),
            joinBaseUrl: 'https://fake.test/rooms',
        );
    }

    public function issueTicket(ClassSession $session, User $user, ParticipantRole $role): JoinTicket
    {
        return new JoinTicket(
            roomUrl: 'https://fake.test/rooms/'.$session->uuid,
            token: 'fake-token-'.$user->getKey().'-'.$role->value,
            expiresAt: CarbonImmutable::now()->addMinutes(10),
            role: $role->value,
        );
    }

    public function hostAction(ClassSession $session, HostAction $action, ?User $target = null, ?User $actor = null): void
    {
        $this->hostActions[] = [
            'action' => $action->value,
            'target' => $target?->getKey() === null ? null : (int) $target->getKey(),
            // Recorded because it is the whole of what a bulk action must get
            // right: «الجميع» excludes the host, and the actor is how it knows
            // which participant that is.
            'actor' => $actor?->getKey() === null ? null : (int) $actor->getKey(),
        ];
    }

    public function closeRoom(ClassSession $session): void
    {
        $this->roomClosed = true;
    }

    public function recording(ClassSession $session): ?RecordingArtifact
    {
        if ($this->onRecording !== null) {
            ($this->onRecording)($session);
        }

        if ($this->recordingError !== null) {
            throw $this->recordingError;
        }

        return $this->pendingRecording;
    }
}
