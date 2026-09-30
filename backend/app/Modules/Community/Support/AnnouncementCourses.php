<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Models\Announcement;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which course an announcement is addressed through — the one question the
 * assistant scope (spec 010 · FR-005) asks of it, spelled once for the door and
 * once for the list so the two cannot disagree.
 *
 * A course announcement is its course; a session's is the session's course; a
 * group's is the group's course. ⚠️ AN `all` ANNOUNCEMENT HAS NO COURSE, and
 * `AssistantScopeDirectory::mayActOnCourse()` refuses that `null` to a confined
 * assistant — addressing the whole workspace is exactly what a confinement
 * exists to stop, and a course-less session's announcement is refused the same
 * way its session is.
 *
 * ⚠️ EVERY READ PINS `workspace_id` EXPLICITLY. The caller names the workspace;
 * a scoped read that came back empty would turn into a `null` course and refuse
 * a confined assistant their own announcement.
 */
final class AnnouncementCourses
{
    public function of(string $scope, ?int $scopeId, int $workspaceId): ?int
    {
        if ($scopeId === null) {
            return null;
        }

        $courseId = match ($scope) {
            Announcement::SCOPE_COURSE => $scopeId,
            Announcement::SCOPE_SESSION => ClassSession::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereKey($scopeId)
                ->value('course_id'),
            Announcement::SCOPE_COHORT => Cohort::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $workspaceId)
                ->whereKey($scopeId)
                ->value('course_id'),
            default => null,
        };

        return $courseId === null ? null : (int) $courseId;
    }

    public function ofAnnouncement(Announcement $announcement): ?int
    {
        return $this->of(
            (string) $announcement->scope,
            $announcement->scope_id === null ? null : (int) $announcement->scope_id,
            (int) $announcement->workspace_id,
        );
    }

    /**
     * Narrow a list to the announcements addressed through these courses — the
     * list form of {@see of()}, in SQL so the page is cut after it.
     *
     * @param  Builder<Announcement>  $query
     * @param  list<int>  $courseIds
     * @return Builder<Announcement>
     */
    public function within(Builder $query, array $courseIds, int $workspaceId): Builder
    {
        return $query->where(fn (Builder $any) => $any
            ->where(fn (Builder $course) => $course
                ->where('scope', Announcement::SCOPE_COURSE)
                ->whereIn('scope_id', $courseIds))
            ->orWhere(fn (Builder $session) => $session
                ->where('scope', Announcement::SCOPE_SESSION)
                ->whereIn('scope_id', ClassSession::query()
                    ->withoutWorkspaceScope()
                    ->select('id')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('course_id', $courseIds)))
            ->orWhere(fn (Builder $cohort) => $cohort
                ->where('scope', Announcement::SCOPE_COHORT)
                ->whereIn('scope_id', Cohort::query()
                    ->withoutWorkspaceScope()
                    ->select('id')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('course_id', $courseIds))));
    }
}
