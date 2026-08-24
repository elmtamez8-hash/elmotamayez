<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\Announcement;
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
 * ⚠️ AND «GROUPS» ARE ABSENT DELIBERATELY (ت-٣). `FR-042` names a group as a
 * possible scope; no group entity exists anywhere in this product, and inventing
 * one here would be a membership model, a screen and a permission smuggled in as
 * an enum value. An unknown scope resolves to NOBODY rather than to everybody —
 * a scope this class does not understand must not become a broadcast.
 */
class AnnouncementAudience
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly SessionAttendanceDirectory $sessions,
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
            default => [],
        };
    }
}
