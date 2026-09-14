<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\Learning\Support\CohortRefusal;
use App\Modules\Learning\Support\PendingTransfer;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Support\Facades\DB;

/**
 * The teacher puts somebody in a group, with no request and no approval
 * (FR-028ط).
 *
 * ⚠️ THE APPROVAL GATE IS ON THE STUDENT'S REQUEST, NOT ON THE TEACHER'S
 * DECISION. Routing this through the queue would mean the teacher submitting a
 * request to themselves.
 *
 * ⚠️ AND IT DROPS WHATEVER THE STUDENT HAD PENDING. A request to move to «الأحد»
 * that is still sitting in the queue after the teacher has already moved them
 * somewhere is a decision nobody can take: approving it would undo what was just
 * done by hand, and leaving it pending is a queue row the student watches for
 * ever. It is dropped with the reason written down.
 *
 * A `closed` group is a legal destination here and an illegal one for a student:
 * closed means "no new joins", which is a statement about the door rather than
 * about the room. Archived is refused for both — see the writer.
 */
class MoveMember extends Action
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    /**
     * @param  string|null  $dropNote  what the student reads when this move drops
     *                                 a transfer request they had pending. The
     *                                 default names the TEACHER, which is true of
     *                                 this Action's only caller today and false
     *                                 the moment a platform officer reaches it
     *                                 (٠٣٤ · FR-008) — so the caller that is not
     *                                 a teacher says so, and every existing
     *                                 caller keeps the sentence it had.
     * @param  bool  $seatAlreadyClaimed  passed by the approval path ALONE
     *                                    (٠٣٤ · FR-024أ · `T021أ`): the seat was
     *                                    taken by an atomic conditional UPDATE
     *                                    **before the money moved**, so claiming
     *                                    it again here leaks a place on every
     *                                    approval — and the second claim can
     *                                    refuse with «full» after the payment has
     *                                    already been captured.
     */
    public function handle(
        Cohort $to,
        User $student,
        User $actor,
        ?string $reason = null,
        ?string $dropNote = null,
        bool $seatAlreadyClaimed = false,
    ): CohortMembership {
        /*
        | ⚠️ THE ENROLMENT IS CHECKED EVEN THOUGH THE TEACHER ASKED. NFR-001أ:
        | an Action that acts on a named student must prove the student is
        | theirs, or a bare uuid parameter is an identity probe — pass anyone's
        | and the response comes back carrying their name.
        */
        if (! $this->enrollments->hasActiveEnrollment($student, (int) $to->course_id)) {
            throw CohortRefusal::notEnrolled();
        }

        return DB::transaction(function () use ($to, $student, $actor, $reason, $dropNote, $seatAlreadyClaimed): CohortMembership {
            /*
            | ⚠️ `null`, SO THE WRITER DERIVES IT (٠٣٤ · FR-005). This line said
            | `TRANSFERRED` literally, and «أضِفْ عضواً» on a student in no group
            | at all is not a transfer — the student read «نُقِلت» in their own
            | history over a membership that was their first. The writer knows,
            | because it has already read whether there was one to leave.
            */
            $membership = CohortMembershipWriter::open(
                $to,
                $student,
                null,
                $actor,
                $reason,
                requireOpen: false,
                seatAlreadyClaimed: $seatAlreadyClaimed,
            );

            PendingTransfer::drop(
                (int) $to->course_id,
                (int) $student->getKey(),
                $actor,
                $dropNote ?? 'نقلك المدرّس إلى مجموعة مباشرةً.',
            );

            return $membership;
        });
    }
}
