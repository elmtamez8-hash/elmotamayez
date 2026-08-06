<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Contracts;

use App\Models\User;
use App\Modules\LiveSessions\Data\BroadcastCapabilities;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Data\RoomHandle;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Exceptions\UnsupportedCapability;
use App\Modules\LiveSessions\Models\ClassSession;

/**
 * Abstraction over a live broadcast provider.
 *
 * Session logic depends on this interface and never names a provider. Adding one
 * is a file in Providers/ plus a case in LiveSessionsServiceProvider — the same
 * shape as VideoProviderInterface (004) and PaymentProviderInterface, both of
 * which have shipped with a single implementation.
 *
 * Note what is NOT here: attendance. No participant_joined, no participant_left,
 * no webhook. The register is built from a heartbeat that reaches our own route,
 * and the server does the arithmetic (research §R3). Three things follow: user
 * story 3 is provable before any contract is signed, a lost provider message
 * cannot leave a student "in the room" forever in a report sent to their
 * guardian, and swapping providers does not rewrite the largest piece of logic
 * in the phase.
 */
interface BroadcastProviderInterface
{
    /** 'null', 'livekit', 'daily', ... Never exposed in a payload (FR-019). */
    public function identifier(): string;

    /**
     * What this provider can actually do.
     *
     * Declared, not assumed. The contract test holds an implementation to what
     * it claims and nothing more, which is what makes deferring the commercial
     * choice safe rather than optimistic.
     */
    public function capabilities(): BroadcastCapabilities;

    /** Creates the room. Idempotent: calling twice must not make two rooms. */
    public function createRoom(ClassSession $session): RoomHandle;

    /**
     * A short-lived ticket for this person, in this role, for this room.
     *
     * Requested on every join and never stored: eligibility is re-evaluated each
     * time (FR-046), and a ticket that outlives the room it opens is a door left
     * ajar.
     */
    public function issueTicket(ClassSession $session, User $user, ParticipantRole $role): JoinTicket;

    /**
     * Mute, remove or end.
     *
     * @throws UnsupportedCapability when the provider does not claim hostControls
     */
    public function hostAction(ClassSession $session, HostAction $action, ?User $target = null): void;

    /** Closes the room. Afterwards no earlier ticket opens it (FR-015). Idempotent. */
    public function closeRoom(ClassSession $session): void;

    /**
     * The recording, or null if it is not ready.
     *
     * Must not throw when it is not ready: "not finished yet" is the expected
     * answer on the first call after every session, not an error.
     */
    public function recording(ClassSession $session): ?RecordingArtifact;
}
