<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use RuntimeException;

/**
 * The door.
 *
 * Everything is decided here and nothing is trusted from the client — least of
 * all the role, which is derived from a permission. A role in a request body is
 * a request to be promoted.
 *
 * Eligibility is re-evaluated on every join (FR-046). The seat was checked when
 * it was taken, possibly weeks ago; an enrolment that lapsed in between must
 * stop the person at the door, not wave them through on the strength of an old
 * decision.
 *
 * Every refusal is the same refusal. A caller who is not entitled learns
 * nothing about whether the session exists, is full, or has ended — a
 * distinguishable error is an enumeration tool (FR-015).
 */
class IssueJoinTicket extends Action
{
    public function __construct(
        private readonly BroadcastProviderInterface $provider,
        private readonly BookingEligibility $eligibility,
        private readonly OpenBroadcastRoom $openRoom,
    ) {}

    /** @throws RuntimeException when this person may not enter, for any reason */
    public function handle(ClassSession $session, User $user): JoinTicket
    {
        $role = $this->roleFor($session, $user);

        if ($role === null || ! $session->joinWindowCovers(now())) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        // The host opening the door is what puts the session live. A student
        // arriving first does not open a room the teacher has not started.
        if ($role === ParticipantRole::Host) {
            $session = $this->openRoom->handle($session);
        } elseif ($session->broadcast_room_id === null) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        return $this->provider->issueTicket($session, $user, $role);
    }

    /**
     * Host, participant, or nobody.
     *
     * Host comes from the permission, participant from a live booking. A teacher
     * needs no booking in their own session; a student with a cancelled seat is
     * not a participant.
     */
    private function roleFor(ClassSession $session, User $user): ?ParticipantRole
    {
        if ($user->can(Permissions::SESSIONS_HOST)
            && (int) $user->last_workspace_id === (int) $session->workspace_id) {
            return ParticipantRole::Host;
        }

        $hasSeat = $session->bookings()
            ->where('student_user_id', $user->getKey())
            ->where('status', BookingStatus::Booked)
            ->exists();

        if (! $hasSeat) {
            return null;
        }

        return $this->eligibility->allows($session, $user)
            ? ParticipantRole::Participant
            : null;
    }
}
