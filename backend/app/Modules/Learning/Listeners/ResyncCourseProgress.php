<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Learning\Events\CourseCompleted;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\CourseProgress;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings every enrolled student's stored percentage back in line with the tree.
 *
 * `progress_pct` is written by `MarkLessonComplete` and by nothing else, so it
 * describes a denominator taken at the student's last completion. Publishing a
 * batch changes that denominator for everyone at once, and until someone
 * completes another lesson their screen keeps the old number: the drop the
 * teacher was shown in the impact preview would be visible to the teacher alone,
 * which is not what `FR-051` says.
 *
 * **Both directions, and the upward one is the dangerous half.** The same
 * endpoint archives and unpublishes. Archive the one item a student had left and
 * their remaining work is zero — but completion is only ever decided when a
 * lesson is completed, and there is no lesson left for them to complete. Without
 * this listener that enrolment would sit at 100% with no `CourseCompleted` and no
 * certificate, permanently. `CourseProgress::sync` is what makes the decision, so
 * this and `MarkLessonComplete` reach it identically.
 *
 * Completion is granted here, never withdrawn (`FR-050`) — `sync()` writes
 * `status` in one direction only, so content added after a student finished
 * lowers their percentage and leaves their certificate alone.
 *
 * **Queued, for the same reason the exam backfill is.** The set is every student
 * of the course, which is not a number this code gets to assume, and each one
 * costs an update and a count. Run inline it would hold the publish request open
 * behind work the teacher is not waiting for.
 */
class ResyncCourseProgress implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use InteractsWithQueue;

    public function handle(CourseStructureChanged $event): void
    {
        $course = $event->course;

        // Counted once for the whole course, not once per student: the
        // denominator is a fact about the TREE and is identical for every
        // enrolment in it. Same scope, same number — the per-student half is the
        // completed count, and that stays per student.
        $total = $course->lessons()->countableForProgress()->count();

        Enrollment::query()
            ->where('course_id', $course->getKey())
            // Active and completed only. A cancelled or expired enrolment is not
            // a student whose percentage anyone is looking at, and `sync()` would
            // happily complete one — the mistake the exam backfill had to fix,
            // where a certificate issued to somebody the API refuses to let open
            // a single lesson.
            ->whereIn('status', ['active', 'completed'])
            // `MarkLessonComplete` opens with `loadMissing('course')` and so does
            // `sync()`; without this that is one SELECT of the same course row per
            // student.
            ->with('course')
            // Chunked by key rather than by offset. The predicate does not shrink
            // as we go — nothing here changes `course_id` or moves a row out of
            // `active`/`completed` except into `completed`, which the filter still
            // covers — so the walk cannot skip a page.
            ->chunkById(200, function ($enrollments) use ($total): void {
                foreach ($enrollments as $enrollment) {
                    $wasComplete = $enrollment->isCompleted();

                    if (CourseProgress::sync($enrollment, $total) && ! $wasComplete) {
                        // Only on the transition. Firing for everyone already
                        // complete would send a "you finished the course"
                        // notification to every past student on every publish.
                        event(new CourseCompleted($enrollment->refresh()));
                    }
                }
            });
    }
}
