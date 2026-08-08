<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;

/**
 * The course's length, derived from what a student is actually asked to do.
 *
 * `courses.duration_seconds` was a number a teacher typed once and never
 * revisited, so it drifted from the first lesson added onwards. It is now the
 * sum of the same set the progress percentage is measured against — published,
 * completable, not a session recording (FR-016).
 *
 * That the two agree is the point: a course advertised as three hours whose
 * denominator counts a different set is two numbers describing one thing.
 */
final class CourseDuration
{
    public static function recompute(Course $course): void
    {
        $seconds = (int) Lesson::query()
            ->where('course_id', $course->getKey())
            ->countableForProgress()
            ->sum('duration_seconds');

        // forceFill: the column is derived, so it is deliberately not fillable
        // from a request. Nothing outside this class may set it.
        $course->forceFill(['duration_seconds' => $seconds])->save();
    }
}
