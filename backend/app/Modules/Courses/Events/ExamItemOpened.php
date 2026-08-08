<?php

declare(strict_types=1);

namespace App\Modules\Courses\Events;

use App\Modules\Courses\Models\Lesson;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An exam item is now open to students, or is asking something different.
 *
 * Fired when a `type = exam` item becomes published, and when a published one's
 * exam or gate changes. Both are the same fact from the students' side: **what
 * this item asks may already have been answered.**
 *
 * That matters because an exam is sat from the exam's own page, so students can —
 * and routinely do — finish one before the teacher ever places it in the tree.
 * The moment the item is published it joins their progress denominator, and no
 * `ExamSubmitted` will ever fire for them again: the ceiling drops below 100% and
 * stays there, so `CourseCompleted` never fires and no certificate issues. Nor is
 * "sit it again" always available — `max_attempts` may be spent.
 *
 * An event rather than Courses calling into Learning: Courses does not know
 * enrolments exist, and the constitution keeps it that way.
 */
class ExamItemOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
    ) {}
}
