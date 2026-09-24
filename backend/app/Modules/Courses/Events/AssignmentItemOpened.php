<?php

declare(strict_types=1);

namespace App\Modules\Courses\Events;

use App\Modules\Courses\Models\Lesson;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An assignment item is now open to students, or points at different homework.
 *
 * Fired when a `type = assignment` item becomes published, and when a published
 * one is repointed. The homework twin of {@see ExamItemOpened}, and for the same
 * reason: **what this item asks may already have been done.**
 *
 * Homework is handed in from `/assignments`, so a student can — and routinely
 * does — hand one in before the teacher ever places it in the tree. The moment
 * the item is published it joins their progress denominator, and no
 * `AssignmentSubmitted` will ever fire for them again once the work is marked
 * (a marked hand-in cannot be replaced). Without a listener on this, their
 * ceiling drops below 100% and stays there: no `CourseCompleted`, no
 * certificate.
 *
 * A separate event rather than widening `ExamItemOpened`: its one listener
 * reads exam attempts and gates, and a type check inside it would be the second
 * spelling of «which type is this» the registry exists to avoid.
 */
class AssignmentItemOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
    ) {}
}
