<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Actions\Action;
use DomainException;
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
        private readonly BroadcastProviderResolver $providers,
        private readonly BookingEligibility $eligibility,
        private readonly OpenBroadcastRoom $openRoom,
    ) {}

    /**
     * ⚠️ TWO EXCEPTION HIERARCHIES LEAVE HERE, AND THE ANNOTATION USED TO NAME
     * ONE. `OpenBroadcastRoom` refuses a terminal, suspended or already-closed
     * session with a `DomainException`, which extends `LogicException` and NOT
     * `RuntimeException` — so a caller that caught the documented type alone let
     * it through to a 500. Both controllers catch both now; this says why there
     * are two.
     *
     * @throws RuntimeException when this person may not enter, for any reason
     * @throws DomainException when the room itself cannot be opened
     */
    public function handle(ClassSession $session, User $user): JoinTicket
    {
        /*
         * ⚠️ THE CLOCK FIRST, AND IT USED TO BE LAST.
         *
         * `roleFor()` runs the whole eligibility chain — the enrolment, the
         * freeze, the withholding, the unlock rule — around a dozen queries. The
         * join window is one comparison against two columns already loaded. So a
         * ping that was going to be refused for the cheapest possible reason paid
         * the most expensive possible price first, and this is asked on EVERY
         * heartbeat by every participant: a tab left open after the lesson kept
         * buying the full chain twice a minute to be told the room had closed.
         *
         * The ANSWER is unchanged, which is what makes the reorder safe: every
         * refusal here is the same refusal whatever its reason (FR-015), so
         * asking in a different order cannot leak an order to anybody.
         */
        if (! $session->joinWindowCovers(now())) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        $role = $this->roleFor($session, $user);

        if ($role === null) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        /*
         * ⚠️ WITHOUT THIS, «إخراج» LASTED ONE REFRESH.
         *
         * Removing somebody used to be a provider call and nothing else: the
         * participant was disconnected, the room stayed open, the seat stayed
         * booked, and this method minted them a fresh ticket a second later. The
         * teacher's one control over a disruptive student cost them an F5.
         * Reported from a real lesson on 2026-08-26.
         *
         * ⚠️ THE HOST IS NEVER READ AGAINST IT. A teacher has an attendance row
         * of their own — deliberately, since `CloseClassSession` judges delivery
         * from it — so a check above this branch would let one host lock another
         * out of their own lesson with the button meant for a student.
         *
         * The refusal is the module's uniform sentence (FR-015) and the cost is
         * accepted rather than hidden: the removed student reads «تأكّد من حجز
         * مقعدك» about a seat that is fine. A distinguishable refusal here would
         * be an enumeration oracle everywhere else, and the same sentence is what
         * every other reason answers.
         */
        if ($role !== ParticipantRole::Host && $this->wasRemoved($session, $user)) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        // The host opening the door is what puts the session live. A student
        // arriving first does not open a room the teacher has not started.
        if ($role === ParticipantRole::Host) {
            $session = $this->openRoom->handle($session);
        } elseif ($session->broadcast_room_id === null) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        return $this->providers->for($session)->issueTicket($session, $user, $role);
    }

    /**
     * Did the host put this person out of this session?
     *
     * `withoutWorkspaceScope()` for the reason every student-facing read needs
     * it: a student is a member of no workspace, so the context is null and the
     * scope adds no condition anyway — but the register is also read from the
     * teacher's request, where it would AND the wrong workspace and find nothing.
     */
    private function wasRemoved(ClassSession $session, User $user): bool
    {
        return Attendance::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $user->getKey())
            ->whereNotNull('removed_at')
            ->exists();
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

        return $this->eligibility->maySit($session, $user)
            ? ParticipantRole::Participant
            : null;
    }
}
