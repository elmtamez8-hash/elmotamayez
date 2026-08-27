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
 * ⚠️ AND `SCOPE_ALL` IS DELIBERATELY EXCLUDED. It reaches this student through
 * the bell and it is about every course they study with this teacher, so
 * repeating it under each course's tab is the same sentence three times — and
 * FR-019 asks for «this course's announcements», not the workspace's. `session`
 * scope is likewise absent: it addresses the seat holders of one hour, and US3
 * adds the fourth word («cohort») that the tab will read beside `course`.
 */
class ReadCourseAnnouncements extends Action
{
    /** @return Collection<int, Announcement> */
    public function handle(Course $course): Collection
    {
        return Announcement::query()
            ->where('scope', Announcement::SCOPE_COURSE)
            ->where('scope_id', $course->getKey())
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
