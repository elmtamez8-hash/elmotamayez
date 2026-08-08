<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use Illuminate\Support\Facades\DB;

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

        // A direct column write, not `$course->save()`.
        //
        // Saving the model fires Scout, so every lesson created or renamed would
        // reindex the course — which made authoring depend on the search engine
        // being up. It fails loudly the moment Meilisearch is down, and the
        // teacher's error is a cURL message about port 7700.
        //
        // Nothing about the search document changed anyway: this column is not
        // in `toSearchableArray()`. The in-memory instance is refreshed so a
        // caller that reads it next sees the new number.
        DB::table('courses')->where('id', $course->getKey())->update(['duration_seconds' => $seconds]);

        $course->setAttribute('duration_seconds', $seconds)->syncOriginalAttribute('duration_seconds');
    }
}
