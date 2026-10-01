<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Modules\Community\Models\Announcement;
use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
use Illuminate\Support\Collection;

/**
 * The notices posted about ONE course, as the student reads them (US2 · FR-019).
 *
 * ⚠️ A READ THAT DID NOT EXIST BEFORE THIS PHASE. `/manage/announcements` is the
 * teacher's list; the student's entire surface has been the notification bell,
 * which is why `DispatchNotification` carries the announcement body verbatim
 * rather than a link to a screen. A tab that shows what was said about this
 * course — after the bell has been cleared — is the thing that was missing.
 *
 * ⚠️ THE EXPLICIT `where` IS THE WHOLE GUARD, AND THE CALLER'S 403 IS THE OTHER
 * HALF. The reader is a student: `users.last_workspace_id` is null, so
 * `WorkspaceContext::id()` is null and `WorkspaceScope::apply()` adds NO
 * condition — `Announcement::query()` here starts as wide as an unauthenticated
 * one. Scoping to this course's id is what narrows it, and the controller's
 * enrolment check is what earns the right to ask about this course at all.
 *
 * ⚠️ `SCOPE_ALL` IS INCLUDED SINCE 2026-10-01 (owner decision). It was left to
 * the bell alone, and a student who marked the bell read had nowhere to find
 * «حصة الغد الساعة الخامسة» again. It is pinned to THIS COURSE'S WORKSPACE —
 * the reader's context is null (above), so without that `where` every
 * workspace's `all` notice would land in this tab. `session` scope is still
 * absent: it addresses the seat holders of one hour.
 */
class ReadCourseAnnouncements extends Action
{
    /** @return Collection<int, Announcement> */
    public function handle(Course $course): Collection
    {
        return Announcement::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $course->workspace_id)
            ->where(fn ($query) => $query
                ->where(fn ($own) => $own
                    ->where('scope', Announcement::SCOPE_COURSE)
                    ->where('scope_id', $course->getKey()))
                ->orWhere('scope', Announcement::SCOPE_ALL))
            // Published and not withdrawn. `hidden_at` rather than a soft delete
            // is what lets moderation still read what it acted on, which is
            // exactly why the student's query has to say so itself.
            ->whereNotNull('published_at')
            ->whereNull('hidden_at')
            // The author travels: a class with an assistant has two people who
            // can post, and «من قال هذا» is the first thing a reader asks.
            ->with('author')
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();
    }
}
