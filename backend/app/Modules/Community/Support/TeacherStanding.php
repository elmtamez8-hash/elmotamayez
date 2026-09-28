<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Marketplace\Models\TeacherProfile;

/**
 * Whether a workspace may be written to by people who do not study there
 * (security review of #276, 2026-09-28).
 *
 * ⛔ A PROSPECT REACHES ONLY A TEACHER THE PLATFORM PUBLISHES. «تواصل مع المدرّس»
 * sits on public pages, but the door takes a bare workspace uuid — so without
 * this a suspended teacher, or an applicant not yet approved, kept receiving
 * messages from strangers the platform had decided they may not court.
 *
 * The authoritative predicate is `TeacherProfile::publiclyListed()` — approved
 * AND listed AND the workspace participating — the same scope every public
 * teacher page is read through, rather than `approval_status` alone: a teacher
 * whose page answers 404 is not somebody a visitor can have found. Asked per
 * WORKSPACE: an academy is reachable while any of its teachers is listed.
 *
 * A subscriber is never asked this: a teacher suspended by the platform still
 * owes their enrolled students an answer.
 */
class TeacherStanding
{
    public function reachableByProspects(int $workspaceId): bool
    {
        return TeacherProfile::query()
            ->publiclyListed()
            ->where('teacher_profiles.workspace_id', $workspaceId)
            ->exists();
    }
}
