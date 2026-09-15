<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Data\JoinTicket;
use App\Modules\LiveSessions\Enums\ParticipantRole;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Modules\LiveSessions\Support\RoomRevocation;
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
        private readonly RoomRevocation $revocation,
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
        $isHost = $this->revocation->isHost($session, $user);

        /*
         | ⛔ **أسئلةُ السحبِ أوّلاً، ومن صنفٍ واحدٍ يسألُه البابانِ معاً.**
         |
         | هذه هي ما يتغيّرُ أثناءَ الحصّةِ ويجبُ أن يُخرِج: النافذةُ والحالةُ
         | والمقعدُ والإخراجُ والغرفة. و{@see BroadcastController::presence()}
         | يسألُ **هذه وحدَها** في كلِّ نبضة، بينما يسألُها هذا البابُ ثمّ يسألُ
         | الاستحقاقَ تحتَها.
         |
         | ⚠️ **والترتيبُ لا يُفشي شيئاً**: كلُّ رفضٍ هنا هو الرفضُ نفسُه مهما
         | كانَ سببُه (FR-015)، وهو ما جعلَ تقديمَ الساعةِ آمناً قبلَ ذلك.
         */
        if (! $this->revocation->stillAdmitted($session, $user)) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        // المضيفُ هو من يفتحُ البابَ، وفتحُه هو ما يجعلُ الحصّةَ حيّة.
        if ($isHost) {
            $session = $this->openRoom->handle($session);

            return $this->providers->for($session)->issueTicket($session, $user, ParticipantRole::Host);
        }

        /*
         | ⛔ **واستحقاقُ البابِ هنا وحدَه، لا في النبضة.** التسجيلُ والإجازةُ
         | والمالُ والواجبُ أسئلةٌ تُحسَمُ عندَ الدخول: إجابتُها لا تتغيّرُ
         | والطالبُ جالسٌ يسمع، وإعادةُ سؤالِها كلَّ ثلاثينَ ثانيةً كانت ترمي
         | طالبةً **دفعَت** خارجَ حصّتِها لاضطرابٍ في رصيدِها.
         */
        if (! $this->eligibility->maySit($session, $user)) {
            throw new RuntimeException('لا يمكنك دخول هذه الحصة الآن.');
        }

        return $this->providers->for($session)->issueTicket($session, $user, ParticipantRole::Participant);
    }
}
