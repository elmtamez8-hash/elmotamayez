<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student moved from one group of a course to another.
 *
 * ⚠️ IT CARRIES THE OLD GROUP'S ID BECAUSE BY THE TIME A LISTENER RUNS, NOTHING
 * ELSE DOES. The membership row is already closed and the student already
 * belongs somewhere else, so a listener that went looking for "the group they
 * left" would find the one they just joined and release the seats they are
 * entitled to keep. Same reasoning as `SessionCancelled` carrying its seat
 * holders.
 *
 * ⚠️ AND IT IS NOT FIRED ON A FIRST JOIN. A student with no previous membership
 * has no seats in another group's timetable to give up, and an event whose every
 * listener returns immediately is a listener list nobody prunes.
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
        public readonly int $fromCohortId,
        public readonly int $toCohortId,
    ) {}
}
