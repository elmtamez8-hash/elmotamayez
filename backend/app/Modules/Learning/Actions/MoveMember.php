<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
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

    public function handle(Cohort $to, User $student, User $actor, ?string $reason = null): CohortMembership
    {
        /*
        | ⚠️ THE ENROLMENT IS CHECKED EVEN THOUGH THE TEACHER ASKED. NFR-001أ:
        | an Action that acts on a named student must prove the student is
        | theirs, or a bare uuid parameter is an identity probe — pass anyone's
        | and the response comes back carrying their name.
        */
        if (! $this->enrollments->hasActiveEnrollment($student, (int) $to->course_id)) {
            throw CohortRefusal::notEnrolled();
        }

        return DB::transaction(function () use ($to, $student, $actor, $reason): CohortMembership {
            $membership = CohortMembershipWriter::open(
                $to,
                $student,
                CohortMembershipEvent::TRANSFERRED,
                $actor,
                $reason,
                requireOpen: false,
            );

            PendingTransfer::drop(
                (int) $to->course_id,
                (int) $student->getKey(),
                $actor,
                'نقلك المدرّس إلى مجموعة مباشرةً.',
            );

            return $membership;
        });
    }
}
