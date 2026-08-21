<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Events\MediaAssetsExpired;

/**
 * A recording whose retention ran out leaves the course tree (spec 013 · FR-031ب).
 *
 * ⚠️ THE EVENT IS NOT OPTIONAL POLISH — IT IS THE SEVENTH ROAD TO THE WORST
 * DEFECT IN THIS PRODUCT, and 016 closed the other six. A recording IS a lesson,
 * and a lesson that stays in the progress denominator while its video no longer
 * exists can never be completed: every enrolled student is capped below 100%,
 * `CourseCompleted` never fires, and no certificate ever issues — permanently,
 * with nothing logged. Archiving the lesson is what removes it from the
 * denominator; `CourseStructureChanged` is what makes every already-enrolled
 * student's percentage recompute against the new one.
 *
 * ⚠️ AND THE EVENT IS FIRED ONCE PER COURSE, NOT ONCE PER LESSON. Fifty
 * recordings of one course expire on the same night the day that course turns
 * two — fifty full resyncs over one identical set of enrolments, which is what
 * actually threatens the sweep's timeout, not the deletes.
 *
 * It lives here rather than in `Media` because the chain from an asset to a
 * course runs through a class session, which is this module's model.
 */
class ArchiveExpiredRecordingLessons
{
    public function handle(MediaAssetsExpired $event): void
    {
        $sessionIds = $event->ownerIdsOf(ClassSession::class);

        if ($sessionIds === []) {
            return;
        }

        /*
        | `withoutWorkspaceScope()` because the sweep runs with no workspace
        | context at all — a job that called `WorkspaceContext::set()` would leak
        | one teacher's workspace into whatever the same worker handled next.
        */
        $lessons = Lesson::query()
            ->withoutWorkspaceScope()
            ->whereIn('class_session_id', $sessionIds)
            ->where('status', '!=', ContentStatus::Archived->value)
            ->get();

        if ($lessons->isEmpty()) {
            return;
        }

        Lesson::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $lessons->modelKeys())
            ->update(['status' => ContentStatus::Archived->value, 'updated_at' => now()]);

        $courseIds = array_values(array_unique(
            $lessons->pluck('course_id')->filter()->map(intval(...))->all(),
        ));

        foreach (Course::query()->withoutWorkspaceScope()->whereIn('id', $courseIds)->get() as $course) {
            CourseStructureChanged::dispatch($course);
        }
    }
}
