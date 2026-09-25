<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

/**
 * Move a course between draft, published and archived — the one door every
 * status change goes through.
 *
 * ⚠️ THE PANEL WROTE THE COLUMN RAW. `/admin`'s course form carried a `status`
 * select saved by Filament's default `$record->update($data)`, so a course
 * published from the panel left no `published` entry in the activity log while
 * the same act through the API (`PublishCourse`) did — and an unpublish or an
 * archive left no trace from either side, because no Action for them existed.
 * The audit log is the one place that answers «who took this course off the
 * marketplace, and when».
 *
 * Publishing is delegated to `PublishCourse` rather than restated, so the API's
 * route and the panel cannot drift into two spellings of one act. No readiness
 * check is added here: `PublishCourse` has none, and inventing one for the
 * panel alone would make the two doors disagree.
 */
class ChangeCourseStatus extends Action
{
    use LogsActivity;

    public function __construct(private readonly PublishCourse $publish) {}

    public function handle(Course $course, CourseStatus $to): Course
    {
        $from = $course->status;

        if ($from === $to->value) {
            return $course;
        }

        if ($to === CourseStatus::Published) {
            return $this->publish->handle($course);
        }

        $course->update(['status' => $to->value]);

        $this->logActivity($to === CourseStatus::Archived ? 'archived' : 'unpublished', $course, [
            'from' => $from,
            'to' => $to->value,
        ]);

        return $course->refresh();
    }
}
