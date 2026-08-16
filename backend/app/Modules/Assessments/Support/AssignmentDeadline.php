<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When this assignment is actually due, for this student.
 *
 * ⚠️ ONE COMPUTER, BECAUSE THREE CALLERS NEED THE SAME ANSWER AND WILL NOT
 * AGREE IF EACH WORKS IT OUT. `SubmitAssignment` stamps the state from it,
 * `MarkMissedSubmissionsJob` decides who to mark from it, and US7's unlock gate
 * reads it again. A second copy of "due_at plus the accommodation unless there
 * is an extension" disagrees with the first on exactly the student the
 * accommodation was granted for — who is then marked missed overnight by the
 * sweep and locked out of the next session by the gate, which is the one person
 * the whole feature exists to protect.
 *
 * The order of precedence is: the student's own extension on THIS assignment
 * wins outright; otherwise the standing accommodation adds whole days; otherwise
 * the assignment's own date. An extension is a decision about one deadline and
 * an accommodation is a decision about a person, so merging them — adding the
 * days on top of an explicit extension — would quietly overrule the teacher who
 * just typed a date.
 */
class AssignmentDeadline
{
    public function __construct(
        private readonly ApplyAccommodation $accommodations,
    ) {}

    /**
     * Null means "no deadline at all", which is a real answer: an assignment
     * with no `due_at` can never be late and can never be swept.
     */
    public function effectiveFor(Assignment $assignment, int $studentId, ?Submission $submission = null): ?CarbonImmutable
    {
        $extension = $submission?->extension_until;

        if ($extension !== null) {
            return CarbonImmutable::parse($extension);
        }

        if ($assignment->due_at === null) {
            return null;
        }

        $days = $this->accommodations->extendedDays((int) $assignment->workspace_id, $studentId);

        return CarbonImmutable::parse($assignment->due_at)->addDays($days);
    }

    /**
     * How late a hand-in at this moment is, in whole minutes. Zero is on time.
     *
     * ⚠️ HANDING IN AT EXACTLY THE DEADLINE IS ON TIME. `diffInMinutes` on an
     * equal pair is zero, and the comparison below is `>` rather than `>=` for
     * the same reason — a boundary that punishes the student who made it with a
     * second to spare is a bug nobody reports because they assume they were late.
     */
    public function lateByMinutes(?DateTimeInterface $deadline, ?DateTimeInterface $at = null): int
    {
        if ($deadline === null) {
            return 0;
        }

        $moment = CarbonImmutable::instance($at ?? CarbonImmutable::now());
        $due = CarbonImmutable::instance($deadline);

        return $moment->greaterThan($due) ? (int) $due->diffInMinutes($moment) : 0;
    }
}
