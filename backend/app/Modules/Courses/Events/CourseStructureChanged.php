<?php

declare(strict_types=1);

namespace App\Modules\Courses\Events;

use App\Modules\Courses\Models\Course;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The set of items a course is measured against has moved.
 *
 * Deliberately not named for publishing, and it was: **deleting** an item moves
 * the denominator exactly as archiving one does, and the event fired from the
 * publish path alone left that door open — a student who had finished three of
 * four items and whose fourth was then DELETED reached zero remaining work with
 * no lesson left for `MarkLessonComplete` to run on. Stored at 75%, no
 * `CourseCompleted`, no certificate, permanently. A name that describes one
 * caller is how the second caller gets forgotten.
 *
 * Both directions matter. Adding an item lowers every enrolled student's
 * percentage; removing one raises it, and can take the remaining work to zero
 * for a student who had exactly that item left.
 *
 * Fired once per OUTERMOST verb. Deleting a section deletes its chapters, which
 * delete their items — announcing at the leaf would queue one full-course resync
 * per lesson, so the sweeps pass `announce: false` and the entry point speaks.
 *
 * The reason this event has to exist at all is that `progress_pct` is written
 * when a LESSON is completed and at no other moment. Without it the number a
 * student sees is the number from their last completion, computed against a
 * denominator that no longer exists — so `FR-051`'s "the drop must remain a drop
 * in the percentage" would be true of the preview shown to the teacher and false
 * of every screen it describes.
 *
 * An event rather than Courses calling Learning: Courses does not know that
 * enrolments exist, and Constitution III keeps it that way.
 */
class CourseStructureChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Course $course,
    ) {}
}
