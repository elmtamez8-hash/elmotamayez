<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Support\CohortRefusal;
use App\Shared\Actions\Action;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A new run of a course.
 *
 * ⚠️ FOR A `group` COURSE ONLY (FR-037). A recorded course has no timetable to
 * be a group of, and an individual one is already one student — a group there
 * would be a room with a capacity of one and a picker with one entry, which is
 * a screen that asks a question with no answers.
 *
 * ⚠️ AND THE RULE IS ENFORCED HERE RATHER THAN ONLY IN THE FORM REQUEST,
 * because the Action is the single entry point the panel, a seeder and the API
 * all share.
 */
class CreateCohort extends Action
{
    public function handle(Course $course, User $creator, string $name, ?string $description = null, ?int $capacity = null): Cohort
    {
        if ($course->course_type !== Course::TYPE_GROUP) {
            throw new CohortRefusal('not_a_group_course', 'المجموعات متاحة لكورسات المجموعة فقط.');
        }

        try {
            // ⚠️ REFRESHED: `status` and `members_count` are deliberately not
            // fillable, so their DB defaults are absent from the instance
            // `create()` returns and the Resource would answer `status: null` on
            // a group that is open.
            return Cohort::query()->create([
                'workspace_id' => $course->workspace_id,
                'course_id' => $course->getKey(),
                'name' => $name,
                'description' => $description,
                'capacity' => $capacity,
                'created_by' => $creator->getKey(),
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            // `unique(course_id, name)`. Two groups called «السبت ٤م» in one
            // course is a picker the student cannot choose from.
            throw new CohortRefusal('duplicate_name', 'يوجد في هذا الكورس مجموعة بهذا الاسم.');
        }
    }
}
