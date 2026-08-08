<?php

declare(strict_types=1);

namespace App\Modules\Courses\Events;

use App\Modules\Courses\Models\Course;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A batch of nodes changed state, so the progress denominator moved.
 *
 * Not "something was published" — the same endpoint pulls nodes back to draft
 * and archives them, and both directions matter here. Adding an item lowers
 * every enrolled student's percentage; archiving one raises it, and can take the
 * remaining work to zero for a student who had exactly that item left.
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
class CourseStructurePublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Course $course,
    ) {}
}
