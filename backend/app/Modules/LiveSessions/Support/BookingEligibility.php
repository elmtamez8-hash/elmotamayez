<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionCreditHolds;
use App\Shared\Contracts\UnlockDirectory;
use App\Shared\Support\CountedNoun;

/**
 * What "an eligible student" means, spelled out.
 *
 * FR-045 makes this a binding definition rather than a description, and FR-047
 * forbids any implicit rule that is not written here. So this class is the whole
 * answer: three conditions, no fourth hiding in a controller.
 *
 * It is asked twice — once when the seat is booked and again when the room is
 * entered (FR-046). Checking once would let a student who lost their enrolment
 * on Tuesday walk into Thursday's session on a seat they were entitled to when
 * they took it.
 */
class BookingEligibility
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly AccountStanding $standing,
        private readonly UnlockDirectory $unlock,
        private readonly SessionCreditHolds $holds,
        private readonly CohortDirectory $cohorts,
    ) {}

    /**
     * The freeze refusal, named because ONE caller has to tell it apart from the
     * others (027 · FR-042).
     *
     * A freeze is a holiday somebody declared on purpose, so the automatic
     * booker must not report it to the student as a seat it failed to get —
     * and it re-runs, so that report would arrive again every time. Every other
     * refusal is news. A constant rather than a repeated string literal: two
     * spellings of this sentence and the comparison below silently stops
     * matching, which brings the notification back with nothing to show why.
     */
    public const FROZEN_REFUSAL = 'هذه الفترة موقوفة مؤقّتاً.';

    /** The reason a student may not book, or null when they may. */
    public function refusalReason(ClassSession $session, User $student): ?string
    {
        if (! $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $session->workspace_id)) {
            return 'لست مسجّلاً عند هذا المدرّس.';
        }

        if ($this->isFrozen($session, $student)) {
            return self::FROZEN_REFUSAL;
        }

        return $this->withholdingRefusal($session, $student);
    }

    /**
     * Which sessions a student may TAKE A SEAT in — the course and the group
     * (owner decision 2026-09-25).
     *
     * ⚠️ `refusalReason()` ASKS ONLY «ARE YOU STUDYING WITH THIS TEACHER AT
     * ALL», and that let a student enrolled in maths book the physics sessions,
     * and a member of the Saturday group book the Sunday group's lesson — a seat
     * in a room they were never placed in, found by uuid rather than by any
     * screen (discovery already hides both). The booking door now asks what the
     * session actually belongs to:
     *
     * - a session with NO course keeps the workspace-level rule above, and only
     *   that: there is no course to be enrolled in, and those are historic
     *   one-offs (a course is required on every schedulable session since Q-7);
     * - a session in a course needs an enrolment in THAT course that still grants
     *   access (`active` or `completed` — `Enrollment::GRANTING_STATUSES`);
     * - a session filed under a group needs a CURRENT membership of that group,
     *   and one filed under a one-to-one group needs to be that student's own
     *   ({@see CohortDirectory::mayHoldSeatIn()}).
     *
     * ⚠️ A SEPARATE METHOD, ASKED AT THE BOOKING DOOR ONLY — `BookSeat`'s three
     * entries, which is also the door `ClaimSubscriptionSeats` walks through.
     * It is deliberately NOT inside `refusalReason()`, which the room's door
     * (`IssueJoinTicket` → `maySit`) also asks: the owner's rule is about who may
     * BOOK, and folding it into the door would evict a student from a seat they
     * already hold the moment a teacher moves them between groups — the thing
     * `GetStudentSchedule` promises never to take back. Visibility is untouched
     * too: this narrows who may book, it widens nothing anyone can see.
     */
    public function bookingScopeRefusal(ClassSession $session, User $student): ?string
    {
        if ($session->course_id === null) {
            return null;
        }

        if (! $this->enrollments->hasActiveEnrollment($student, (int) $session->course_id)) {
            return 'هذه الحصة تابعة لكورس لست مسجّلاً فيه.';
        }

        if ($session->cohort_id !== null
            && ! $this->cohorts->mayHoldSeatIn($student, (int) $session->cohort_id)) {
            return 'هذه الحصة لمجموعة لست عضواً فيها.';
        }

        return null;
    }

    /**
     * The money condition, and the fourth of the three above (006 · FR-032).
     *
     * Asked HERE rather than in a controller, because this class is the binding
     * definition of an eligible student (FR-045 · FR-047) and it is asked twice —
     * at booking and again at the door. A rule enforced at one of those lets a
     * student who ran out on Tuesday walk into Thursday's session.
     *
     * ⚠️ The refusal names the number and the way to pay. SC-010 is about what
     * the student can DO next; "غير مسموح" is a dead end wearing the same status
     * code.
     */
    private function withholdingRefusal(ClassSession $session, User $student): ?string
    {
        // Permanently nullable (spec 006's backfill explains why), and
        // withholding is per COURSE — a session attached to none has no balance
        // to be withheld against, so there is nothing to ask.
        if ($session->course_id === null) {
            return null;
        }

        /*
        | ⚠️ ONE QUESTION, NOT TWO. The verdict and the number are the same walk —
        | the account, the balances, the workspace, its exam window — and asking
        | them separately walked it twice on every refusal.
        */
        // The seat's own room size and date — a group-only plan does not lift
        // withholding on a one-to-one lesson, nor a plan ending before the day.
        $money = $this->standing->refusalFor($student, (int) $session->course_id, $session->type->value, $session->starts_at);

        if (! $money['withheld']) {
            return null;
        }

        $needed = $money['credits_needed'];

        return 'رصيدك في هذا الكورس لا يكفي لحجز حصة جديدة. تحتاج '.CountedNoun::of($needed, CountedNoun::SESSIONS_OBJECT).' على الأقل، وتُشترى من صفحة الأرصدة.';
    }

    /**
     * The reason a student may not OPEN this session — everything above, plus
     * spec 008's unlock condition (FR-036 → FR-042).
     *
     * ⚠️ A SECOND ENTRY POINT, AND KEEP THE SEPARATION. `refusalReason()` is the
     * question a seat-RELEASING caller would ask; folding the unlock condition
     * into it would repossess a paid-for seat over a missed piece of homework.
     * Losing your enrolment or running out of credit is a reason to release a
     * seat; not having done your homework is a reason not to be given the NEXT
     * one. (The nightly sweep that asked it, `ReleaseIneligibleBookings`, was
     * deleted 2026-09-23 — specs 006 and 027 had already decided against it.)
     *
     * Asked at both doors FR-041 names — booking, and entering the room.
     */
    public function openingRefusal(ClassSession $session, User $student): ?string
    {
        $refusal = $this->refusalReason($session, $student);

        if ($refusal !== null) {
            return $refusal;
        }

        $frozen = $this->frozenCreditRefusal($session, $student);

        if ($frozen !== null) {
            return $frozen;
        }

        return $this->unlock->refusalFor($student, (int) $session->getKey());
    }

    /**
     * ٠٣٥ · T042 — the credits already promised to OTHER seats.
     *
     * ⛔ AND IT IS HERE AND NOT IN `refusalReason()`, for the reason written
     * above that method's sibling. Every booked seat holds a credit by
     * definition, so a seat-releasing caller asking `refusalReason()` would
     * repossess every seat on the platform — each one refused for holding
     * exactly the credit it is entitled to hold.
     *
     * ⚠️ THIS IS NO LONGER ON THE HEARTBEAT, AND THE NUMBER IT USED TO CITE WAS
     * ITS OWN DOING. It said «the budget is 15 against a steady state of 14» —
     * and this method's one query is what turned that 14 into a 15, so the
     * comment described the world before itself. `presence()` asks
     * `RoomRevocation` now and never reaches here: running out of credit is a
     * question about the NEXT booking, not about the hour a student has already
     * paid for and is sitting in.
     *
     * The happy path is still one query, for the door's sake.
     *
     * ⚠️ AND THE SENTENCE CARRIES THE DATE, because a refusal a student can do
     * nothing with is the shape FR-013 forbids. «لا رصيد» to somebody whose
     * credits come back on Thursday is false as well as useless.
     */
    private function frozenCreditRefusal(ClassSession $session, User $student): ?string
    {
        if ($session->course_id === null) {
            return null;
        }

        if ($this->holds->availableFor($student, (int) $session->course_id) >= 1) {
            return null;
        }

        /*
        | ⛔ THIS ASKS «CAN YOU FUND ANOTHER SEAT», AND AT THE ROOM'S DOOR THE SEAT
        | IS ALREADY FUNDED. `availableFor` is remaining − held, and `held` includes
        | the hold placed for THIS session — so a student who spent their last
        | credit booking a lesson was refused entry to that very lesson (measured:
        | one credit ⇒ 403, two ⇒ 200), with `/eligibility` saying nothing.
        |
        | A seat already held is the answer, whether a hold or a subscription
        | funded it. At booking no such row exists yet, so the check still bites
        | there. Unscoped: the student may be stamped with another workspace.
        */
        if ($session->bookings()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())
            ->where('status', BookingStatus::Booked)
            ->exists()) {
            return null;
        }

        $held = $this->holds->heldFor($student, (int) $session->course_id);

        if ($held['first_release_at'] === null) {
            // Nothing frozen and nothing available: an empty balance, which the
            // withholding refusal above has already explained in its own words
            // whenever the course is withheld. Saying it twice in two different
            // sentences is two answers to one question.
            return null;
        }

        $back = (date_create_immutable($held['first_release_at']) ?: null)?->format('Y-m-d H:i');

        $heldCount = CountedNoun::of((int) $held['held'], ['one' => 'حصة واحدة', 'two' => 'حصتان', 'few' => 'حصص', 'many' => 'حصة', 'other' => 'حصة']);

        return "رصيدك محجوزٌ لحصصٍ أخرى ({$heldCount}). أوّل ما يعود منه بعد انتهاء حصة {$back}، أو اشترِ رصيداً من صفحة الأرصدة.";
    }

    public function maySit(ClassSession $session, User $student): bool
    {
        return $this->openingRefusal($session, $student) === null;
    }

    /**
     * Whether a freeze covers this student on this session's date.
     *
     * Read, never written (research §R11): the freeze changes what queries
     * answer, not what rows say. That is why resuming afterwards is not an
     * operation that can fail halfway.
     */
    private function isFrozen(ClassSession $session, User $student): bool
    {
        return FreezePeriod::query()
            // The session's workspace, not the reader's: a teacher's holiday is
            // declared in their own workspace, and this may be called while no
            // workspace is current at all.
            ->withoutWorkspaceScope()
            ->where('workspace_id', $session->workspace_id)
            ->covering($session->starts_at, (int) $student->getKey())
            ->exists();
    }
}
