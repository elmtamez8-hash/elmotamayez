<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\Learning\Support\CohortPricing;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;

/**
 * The first join, which needs nobody's approval (FR-028د).
 *
 * ⚠️ AND THAT IS A DECISION, NOT AN OVERSIGHT. This is the step that OPENS the
 * content the student has already paid for (FR-028أ) — an approval gate on it
 * would be an approval gate on the purchase. Every move afterwards goes through
 * `RequestTransfer`, because by then the student already has what they bought
 * and is asking for a different timetable.
 *
 * The enrolment is asked through the directory rather than queried here, so
 * "entitled to this course" has one implementation.
 */
class JoinCohort extends Action
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly CohortDirectory $cohorts,
        private readonly CohortPricing $pricing,
    ) {}

    public function handle(Cohort $cohort, User $student): CohortMembership
    {
        $courseId = (int) $cohort->course_id;

        if (! $this->enrollments->hasActiveEnrollment($student, $courseId)) {
            throw CohortRefusal::notEnrolled();
        }

        /*
        | ⚠️ THE READ IS COURTESY; THE UNIQUE INDEX IS THE GUARD. Two taps at the
        | same instant both pass this line, and the writer's insert is where the
        | second one loses — with its claimed seat given back. Checking here as
        | well is what turns that race into a sentence the student can read
        | rather than a duplicate-key error.
        */
        if ($this->cohorts->hasOpenMembership($student, $courseId)) {
            throw CohortRefusal::alreadyMember();
        }

        /*
        | ⛔ THE PRICE CONDITION LIVES HERE AND NOT IN THE WRITER (٠٣٦ · T048).
        | `CohortMembershipWriter::open()` has FOUR callers, and one of them runs
        | on a queue worker AFTER the money has committed — an approved
        | subscription being turned into a membership. A throw there leaves a
        | student who has paid with no group, no message anybody sees, and a
        | `failed_jobs` row as the only trace. This door is the one a student
        | walks through of their own accord, and it is the only one where a
        | refusal is something they asked for and can read.
        |
        | ⚠️ AND IT IS ASKED OF THE DOOR, NOT OF THE SCREEN. Dropping the card
        | from the picker is a courtesy; a uuid typed straight at this route is
        | what the gate is actually for.
        */
        if (! $this->pricing->stampOne($cohort)->priceReaches()) {
            throw CohortRefusal::notListed();
        }

        return CohortMembershipWriter::open(
            $cohort,
            $student,
            CohortMembershipEvent::JOINED,
            $student,
        );
    }
}
