<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\Announcement;
use App\Modules\Community\Support\AnnouncementCourses;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

/**
 * Who may address a teacher's whole class at once (FR-042).
 *
 * ⚠️ ITS OWN PERMISSION, NOT `CHAT_REPLY` REUSED — and that is a departure from
 * `GradingSchemePolicy` two files away, which reuses one deliberately. The test
 * is whether the two powers are over the same people in the same way. Weighting
 * grades and writing an assessment are both the teacher's judgement of one
 * student's term, so one name serves. Answering a question in a thread the
 * student opened, and sending three hundred families a message they cannot reply
 * to at all (FR-045), are not: the first is invited and private, the second goes
 * out in the teacher's name to people who never asked for it. An assistant
 * trusted to answer questions is not thereby trusted to announce a change of fees.
 *
 * It therefore costs a fifth backfill migration, which is the price and not an
 * oversight — a new constant reaches nobody who already exists, because
 * `SeedDefaultRoles` runs once at workspace creation.
 */
class AnnouncementPolicy
{
    /**
     * The screen as a whole (`Announcement::class`), or one announcement.
     *
     * ⛔ ONE ANNOUNCEMENT ALSO ASKS THE ASSISTANT SCOPE (spec 010 · FR-005,
     * 2026-09-30), beside the permission and never instead of it: a confined
     * assistant publishes, edits and withdraws only an announcement addressed
     * through one of their courses — a course, a session of one, a group of
     * one. An `all` announcement has no course and is refused to them
     * ({@see AnnouncementCourses}). Before this, the owner ticking
     * `announcements.manage` onto one assistant let them address, and take
     * back, every announcement of the workspace.
     */
    public function manage(User $user, ?Announcement $announcement = null): Response
    {
        if (! $user->can(Permissions::ANNOUNCEMENTS_MANAGE)) {
            return Response::deny();
        }

        if ($announcement === null) {
            return Response::allow();
        }

        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            (int) $announcement->workspace_id,
            app(AnnouncementCourses::class)->ofAnnouncement($announcement),
        )
            ? Response::allow()
            : Response::deny('هذا الإعلان خارج نطاق عملك.');
    }
}
