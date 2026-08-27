<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\Announcement;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;

/**
 * Who an announcement is addressed to — the whole of `FR-043`, in one place.
 *
 * ⚠️ ONE PLACE, AND THAT IS THE POINT. The fan-out needs the audience and so does
 * the publisher's «how many were told» — spelled twice they disagree the first
 * time a scope gains a condition, and the teacher is then shown a denominator
 * nobody was ever sent. It is asked of the DIRECTORIES rather than of Learning's
 * and LiveSessions' models, which Constitution III forbids Community to touch.
 *
 * ⚠️ AND «GROUPS» ARRIVED IN 021, ON THE CONDITION 010 SET WHEN IT REFUSED THEM.
 * The objection was never to the scope but to inventing an entity for it — «a
 * membership model, a screen and a permission smuggled in as an enum value». The
 * membership model, the screen and the permission are all real now, so the fourth
 * arm asks the DIRECTORY exactly as the other three do.
 *
 * ⚠️ `default => []` STAYS, AND IT IS THE LOAD-BEARING LINE OF THIS CLASS. An
 * unknown scope resolves to NOBODY rather than to everybody: a scope this class
 * does not understand must not become a broadcast to every student the teacher
 * has.
 */
class AnnouncementAudience
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly SessionAttendanceDirectory $sessions,
        private readonly CohortDirectory $cohorts,
    ) {}

    /** @return list<int> user ids, ascending */
    public function userIdsFor(Announcement $announcement): array
    {
        $workspaceId = (int) $announcement->workspace_id;

        return match ($announcement->scope) {
            Announcement::SCOPE_ALL => $this->enrollments->activeStudentIdsFor($workspaceId),
            Announcement::SCOPE_COURSE => $announcement->scope_id === null
                ? []
                : $this->enrollments->activeStudentIdsFor($workspaceId, $announcement->scope_id),
            Announcement::SCOPE_SESSION => $announcement->scope_id === null
                ? []
                : $this->sessions->seatHolderUserIds($announcement->scope_id, $workspaceId),
            /*
            | ⚠️ THE ACTIVE MEMBERS, NOT EVERYONE WHO WAS EVER IN THE GROUP. A
            | notice about next Saturday's lesson sent to the student who moved
            | away last month is a message about a room they will not be in — and
            | it is the mirror of the thread's read rule, which deliberately DOES
            | reach them: keeping an old answer is a right, being told about a
            | future they are not part of is noise.
            */
            Announcement::SCOPE_COHORT => $announcement->scope_id === null
                ? []
                : $this->cohorts->activeMemberIdsFor($announcement->scope_id),
            default => [],
        };
    }
}
