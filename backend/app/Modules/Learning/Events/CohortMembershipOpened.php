<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student entered a group of a course — moved into it, or joined their first.
 *
 * ⚠️ IT CARRIES THE OLD GROUP'S ID BECAUSE BY THE TIME A LISTENER RUNS, NOTHING
 * ELSE DOES. The membership row is already closed and the student already
 * belongs somewhere else, so a listener that went looking for "the group they
 * left" would find the one they just joined and release the seats they are
 * entitled to keep. Same reasoning as `SessionCancelled` carrying its seat
 * holders.
 *
 * ⚠️ IT USED TO BE WITHHELD ON A FIRST JOIN, AND SPEC 052 REPEALED THAT REASON.
 * The old rule was sound while the only listener RELEASED seats: a student with
 * no previous membership has none to give up, and an event whose every listener
 * returns immediately is a listener list nobody prunes. Seating a member is the
 * mirror half of that listener, and it is the FIRST join that most needs it —
 * the ordinary case is a teacher generating a term on Sunday and students
 * joining through the week, which under the old rule seated nobody at all.
 * `fromCohortId` is nullable for exactly that: null means «came from nowhere»,
 * and the release arm is skipped on it while the booking arm always runs.
 * (FR-023: a comment citing a repealed reason must say that it was repealed.)
 *
 * Carries integers rather than models: it crosses into LiveSessions, which owns
 * the seat and must not be handed a `Cohort` (Constitution III).
 */
class CohortMembershipOpened
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $courseId,
        public readonly int $studentUserId,
        public readonly ?int $fromCohortId,
        public readonly int $toCohortId,
    ) {}
}
