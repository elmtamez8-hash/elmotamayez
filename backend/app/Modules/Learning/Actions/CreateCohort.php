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
 * ⚠️ OPEN TO EVERY COURSE, AND IT WAS NOT UNTIL 2026-09-09. This refused
 * anything whose `course_type` was not `group`, on the reasoning that «a
 * recorded course has no timetable to be a group of» — which is false on this
 * platform, because **`courses.course_type` has never had a writer**. It is
 * fillable, it defaults to `recorded` in its own migration, and no request,
 * form, Action or seeder assigns it: 91 of 96 rows say `recorded` (measured
 * 2026-09-09), including a course carrying seventeen live sessions whose owner
 * was told groups were «available for group courses only» about a
 * classification nobody ever made.
 *
 * A rule that enforces an unmade decision is not a rule. The teacher decides
 * whether a course runs in groups by creating one — which is a decision they
 * can see, undo by archiving, and take one course at a time.
 *
 * ⚠️ AND THE FIRST GROUP OF A COURSE IS A CONSEQUENCE, NOT A SETTING. From that
 * moment `CohortSessionVisibility` hides every session still carrying no group,
 * and the curriculum gate (FR-028أ) asks every enrolled student to join one. The
 * screen says so beside the button; this Action does not, because a refusal
 * would be the unmade decision again wearing a different word.
 */
class CreateCohort extends Action
{
    public function handle(Course $course, User $creator, string $name, ?string $description = null, ?int $capacity = null): Cohort
    {
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
