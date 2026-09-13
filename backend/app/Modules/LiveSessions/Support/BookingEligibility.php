<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionCreditHolds;
use App\Shared\Contracts\UnlockDirectory;

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

        if (! $this->standing->isWithheld($student, (int) $session->course_id)) {
            return null;
        }

        $needed = $this->standing->creditsNeededFor($student, (int) $session->course_id);

        return "رصيدك في هذا الكورس لا يكفي لحجز حصة جديدة. تحتاج {$needed} حصة على الأقل، وتُشترى من صفحة الأرصدة.";
    }

    /**
     * The reason a student may not OPEN this session — everything above, plus
     * spec 008's unlock condition (FR-036 → FR-042).
     *
     * ⚠️ A SECOND ENTRY POINT, AND THE SEPARATION IS LOAD-BEARING.
     * `ReleaseIneligibleBookings` sweeps booked seats through `allows()` and
     * CANCELS the ones it finds ineligible. Folding the unlock condition into
     * `refusalReason()` would therefore repossess a paid-for seat over a missed
     * piece of homework — a punishment no requirement asks for, delivered by a
     * nightly job with a cancellation notice attached. Losing your enrolment or
     * running out of credit is a reason to release a seat; not having done your
     * homework is a reason not to be given the NEXT one.
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
     * above that method's sibling: `ReleaseIneligibleBookings` sweeps booked
     * seats through `allows()` and CANCELS what it finds ineligible. Every booked
     * seat holds a credit by definition, so folding this in would have the
     * nightly job repossess every seat on the platform — each one refused for
     * holding exactly the credit it is entitled to hold.
     *
     * ⚠️ THE HAPPY PATH IS ONE QUERY AND THE DATE IS ON THE REFUSAL BRANCH. This
     * chain is re-run by `BroadcastController::presence()` on every heartbeat,
     * and its budget is 15 against a steady state of 14 with «the ceiling is not
     * raised» written above the number.
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

        $held = $this->holds->heldFor($student, (int) $session->course_id);

        if ($held['first_release_at'] === null) {
            // Nothing frozen and nothing available: an empty balance, which the
            // withholding refusal above has already explained in its own words
            // whenever the course is withheld. Saying it twice in two different
            // sentences is two answers to one question.
            return null;
        }

        $back = (date_create_immutable($held['first_release_at']) ?: null)?->format('Y-m-d H:i');

        return "رصيدك محجوزٌ لحصصٍ أخرى ({$held['held']} حصة). أوّل ما يعود منه بعد انتهاء حصة {$back}، أو اشترِ رصيداً من صفحة الأرصدة.";
    }

    /**
     * ⚠️ THE SWEEP'S QUESTION, DELIBERATELY NARROWER. See openingRefusal() for
     * why the unlock condition is not asked here.
     */
    public function allows(ClassSession $session, User $student): bool
    {
        return $this->refusalReason($session, $student) === null;
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
