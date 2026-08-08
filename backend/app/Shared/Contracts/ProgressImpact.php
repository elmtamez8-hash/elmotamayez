<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * What changing a course's countable items would do to the people enrolled in it.
 *
 * Exists so the authoring surface can show a teacher the impact of a publish
 * (`016 FR-049`) without reaching into Learning's models, which Constitution III
 * forbids. The dependency between those two modules runs one way — Learning
 * reads the course tree, Courses does not know enrolments exist — and this
 * interface is what keeps it that way. Same shape as {@see EnrollmentDirectory}:
 * Learning owns the enrolment and binds the implementation.
 *
 * A query, not an event: the answer is needed before the response is written.
 *
 * The two lesson-id sets are computed by the caller, because deciding what a
 * course tree WOULD contain is the caller's own subject. What happens to a
 * percentage once those sets are known is not.
 */
interface ProgressImpact
{
    /**
     * @param  list<int>  $before  lesson ids currently in the denominator
     * @param  list<int>  $after  lesson ids that would be, after the change
     * @param  list<array{lesson_id: int, exam_id: int, gate: string}>  $openingExamItems
     *                                                                                     exam items the same change would open — students who already
     *                                                                                     submitted those exams get credited the moment it lands, so a
     *                                                                                     preview that ignored them would report a drop nobody suffers
     * @return array{affected: int, drop: int, gain: int} `drop` is zero or
     *                                                    negative, `gain` zero or positive — the extremes, not a roster
     */
    public function of(int $courseId, array $before, array $after, array $openingExamItems): array;
}
