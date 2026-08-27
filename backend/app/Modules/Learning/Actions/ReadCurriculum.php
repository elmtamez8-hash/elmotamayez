<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\CohortGate;
use App\Modules\Learning\Support\CourseProgress;
use App\Modules\Learning\Support\CurriculumView;
use App\Modules\Learning\Support\LessonGate;
use App\Shared\Actions\Action;

/**
 * The whole course as the student sees it — every item with its state and, when
 * it is shut, the reason (FR-008 · FR-009 · SC-004).
 *
 * ⚠️ IT DECIDES NOTHING. Every answer here comes from {@see LessonGate::forTree()},
 * which is the SAME decision `/learn/lessons/{lesson}` enforces at the door, and
 * the two counts come from {@see CourseProgress}, which is the same formula
 * `MarkLessonComplete` and the publish preview use. Both of those are the
 * spec's own constraint and not a preference: a screen that computed its own
 * version of "open" would put one answer in front of the student and another
 * behind the button, which is the defect that made a paid-for recording
 * unreachable in 018 and the one `BookingEligibility` was fixed for in 017.
 *
 * ⚠️ AND THE COST IS FLAT. Everything is read in bulk before the walk, so a
 * 200-lesson course costs what a 10-lesson one does — measured by
 * `CurriculumQueryBudgetTest`, which asserts the FIELDS are present as well as
 * the count, because dropping an eager load beside a `whenLoaded` makes the page
 * cheaper and empty rather than expensive.
 */
class ReadCurriculum extends Action
{
    public function handle(Enrollment $enrollment): CurriculumView
    {
        $enrollment->loadMissing(['course', 'workspace']);

        // `array_values`, so the shape really is a list: `Collection::all()`
        // preserves keys, and the walk downstream depends on the order alone.
        $lessons = array_values($enrollment->orderedLessons()->all());

        return new CurriculumView(
            enrollment: $enrollment,
            lessons: $lessons,
            access: LessonGate::forTree($enrollment, $lessons),
            completedIds: $enrollment->progress()
                ->where('status', 'completed')
                ->pluck('lesson_id')
                ->map(intval(...))
                ->flip()
                ->all(),
            // ⚠️ ASKED OF `CourseProgress`, NEVER RE-DERIVED FROM THE SETS ABOVE.
            // The two are the same number today; the moment the denominator gains
            // a condition, a hand-rolled intersection here starts showing the
            // student a percentage nothing else in the product agrees with —
            // `SC-018` is the promise that there is exactly one denominator.
            completedCount: CourseProgress::completed($enrollment),
            countableCount: CourseProgress::total($enrollment),
            // ⚠️ ASKED HERE AND NOT IN THE RESOURCE. A Resource that issues a
            // query is a Resource that issues it once per row the day somebody
            // moves the block down into `row()` — and the gate's own hot-path
            // predicate is already answered once inside `LessonGate::forTree()`.
            cohortGate: CohortGate::describe($enrollment->student, (int) $enrollment->course_id),
        );
    }
}
