<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Modules\Courses\Models\Course;
use App\Modules\Whiteboard\Models\Board;
use Illuminate\Support\Collection;

/**
 * «The board's owning teacher» — owner decision D1 (2026-10-01), derived, never stored.
 *
 * A board linked to a course belongs to THE COURSE'S TEACHER, even when an
 * assistant created it: in a one-teacher academy the owner is that teacher, and a
 * creator-owns rule would lock them out of editing (or taking over) the board their
 * own assistant prepared for their own course. A course-less board belongs to its
 * creator.
 *
 * «The course's teacher» is `Course::teacherUser()` and nothing else — never
 * `created_by` or `teacher_profile_id` first (gotchas/courses.md). The course is
 * read WITH trashed rows: courses are soft-deleted, the board keeps pointing at
 * the course, and its teacher still owns what was prepared for it.
 */
final class BoardOwnership
{
    public static function owningTeacherId(Board $board): int
    {
        $course = $board->course_id === null ? null : $board->course;

        return $course instanceof Course
            ? ($course->teacherUser()?->getKey() ?? $board->owner_user_id)
            : $board->owner_user_id;
    }

    /**
     * Load what `owningTeacherId()` reads for a whole page of boards — the course,
     * its creator and its teacher profile's user — and answer
     * `Course::creatorTeaches()` for all of them at once. A Resource runs once per
     * row, so without this every row of the list costs its own queries.
     *
     * @param  Collection<int, Board>  $boards
     */
    public static function primeFor(Collection $boards): void
    {
        $boards->loadMissing(['course.creator', 'course.teacherProfile.user']);

        Course::primeCreatorTeaches(
            $boards->pluck('course')->filter()->unique('id')->values(),
        );
    }
}
