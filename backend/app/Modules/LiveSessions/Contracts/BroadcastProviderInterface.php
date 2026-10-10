<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Contracts;

use App\Models\User;
use App\Modules\LiveSessions\Data\BroadcastCapabilities;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Data\PublishRights;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Data\RoomHandle;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Exceptions\BroadcastProviderUnavailable;
use App\Modules\LiveSessions\Exceptions\InvalidBroadcastSignature;
use App\Modules\LiveSessions\Exceptions\UnsupportedCapability;
use App\Modules\LiveSessions\Models\ClassSession;

/**
 * Abstraction over a live broadcast provider.
 *
 * Session logic depends on this interface and never names a provider. Adding one
 * is a file in Providers/ plus a case in LiveSessionsServiceProvider — the same
 * shape as MediaProviderInterface (004) and PaymentProviderInterface, both of
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
    /**
     * The implementation's own short identifier, used for `sessions.provider`
     * and in log lines. Never exposed in a payload (FR-019).
     *
     * ⚠️ No vendor is named here, not even as an example: `ProviderNameContainmentTest`
     * greps `app/` for one, and the interface is the first place a name spreads
     * from — a docblock listing candidates is how "the adapter is the only file
     * that knows" quietly stops being true (017 FR-002).
     */
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
     *
     * ⚠️ `$rights` IS WHAT THE HOST DECIDED, AND THE TICKET MUST CARRY IT
     * (2026-09-30). A mute that lives only in a live connection lasts one
     * refresh — the student reloads, gets a fresh ticket, and speaks again. The
     * caller reads the stored decision (`RoomMediaRights`); the provider only
     * spells it in its own vocabulary.
     */
    public function issueTicket(ClassSession $session, User $user, ParticipantRole $role, PublishRights $rights): JoinTicket;

    /**
     * Remove, or clear the room's signals — one person, or the whole room.
     *
     * ⚠️ MUTING IS NOT HERE ANY MORE (2026-09-30): it is a PERMISSION, applied
     * through {@see applyPublishRights()}, so a student cannot undo it with one
     * tap of their own microphone button. `Mute`/`MuteAll` never reach this
     * method.
     *
     * `$target` is the person a single action names. `$actor` is the host who
     * pressed the button, and it exists for the BULK forms: «الجميع» means
     * everyone being taught and never the teacher, and the provider is the only
     * place that sees the participant list a bulk action walks.
     *
     * ⚠️ IT RETURNS THE IDENTITIES IT ACTUALLY ACTED ON, and that is what makes
     * a removal outlive the disconnect. Being removed has to be RECORDED or the
     * student is back a refresh later — and the caller cannot work out who was in
     * the room without asking the provider, while re-deriving "who is present"
     * from `last_ping_at` beside `RecordPresencePing`'s own arithmetic is the
     * two-spellings defect this repository has paid for four times. Empty for
     * `End`, which is ours and never reaches an implementation.
     *
     * ⚠️ AND A BULK FORM ACTS ON `$studentIdentities` AND NOBODY ELSE. Skipping
     * the actor alone let «أخرِج الجميع» remove a second teacher or an assistant
     * host; the caller now names the seat holders being taught, and anyone not
     * in that list — every host, every assistant, the recorder — is untouched.
     *
     * @param  list<string>  $studentIdentities  the only identities a bulk form may touch
     * @return list<string> the participant identities affected — our user uuids
     *
     * @throws UnsupportedCapability when the provider does not claim hostControls
     */
    public function hostAction(ClassSession $session, HostAction $action, ?User $target = null, ?User $actor = null, array $studentIdentities = []): array;

    /**
     * Give each named participant exactly these publish rights, NOW.
     *
     * ⚠️ IT REPLACES WHAT THEY HAD — the provider's permission update is a whole
     * set, not a patch — so an implementation must restate everything a student
     * keeps (subscribe, publish the camera, raise a hand) beside what changed.
     * Leaving one out is how «mute» becomes «you can no longer see the lesson».
     *
     * ⚠️ IT NEVER ENDS A LESSON AND NEVER DISCONNECTS ANYONE. It narrows or
     * widens the microphone and the screen; nothing else. Somebody in the map who
     * is not in the room right now is skipped rather than raised: the decision is
     * stored by the caller and reaches them in their next ticket.
     *
     * @param  array<string, PublishRights>  $rights  identity (our user uuid) ⇒ rights
     * @return list<string> the identities it actually applied to
     *
     * @throws UnsupportedCapability when the provider does not claim hostControls
     * @throws BroadcastProviderUnavailable when the provider cannot be reached
     */
    public function applyPublishRights(ClassSession $session, array $rights): array;

    /** Closes the room. Afterwards no earlier ticket opens it (FR-015). Idempotent. */
    public function closeRoom(ClassSession $session): void;

    /**
     * The recording, or null if it is not ready.
     *
     * Must not throw when it is not ready: "not finished yet" is the expected
     * answer on the first call after every session, not an error.
     */
    public function recording(ClassSession $session): ?RecordingArtifact;

    /**
     * A participant just walked into one of our rooms, read off the provider's
     * own signed notification (security scan 2026-10-10, F14).
     *
     * ⚠️ THE ADAPTER VERIFIES AND PARSES, the caller decides. The signature, the
     * event names and the room-naming scheme are the provider's vocabulary, so
     * they stay in the one file that may know it; the caller gets back our two
     * identifiers and asks `RoomRevocation` whether that person may be there.
     *
     * Null for anything that is not a STANDARD participant joining one of our
     * rooms — the recorder joins as a participant of its own kind.
     *
     * @return array{session_uuid: string, identity: string}|null
     *
     * @throws UnsupportedCapability when the provider sends no such notification
     *                               or is not configured to verify one
     * @throws InvalidBroadcastSignature when the signature does not hold
     */
    public function participantJoined(string $body, ?string $signature): ?array;
}
