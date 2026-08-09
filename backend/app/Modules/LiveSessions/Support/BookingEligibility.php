<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Shared\Contracts\AccountStanding;
use App\Shared\Contracts\EnrollmentDirectory;

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
    ) {}

    /** The reason a student may not book, or null when they may. */
    public function refusalReason(ClassSession $session, User $student): ?string
    {
        if (! $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $session->workspace_id)) {
            return 'لست مسجّلاً عند هذا المدرّس.';
        }

        if ($this->isFrozen($session, $student)) {
            return 'هذه الفترة موقوفة مؤقّتاً.';
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

    public function allows(ClassSession $session, User $student): bool
    {
        return $this->refusalReason($session, $student) === null;
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
