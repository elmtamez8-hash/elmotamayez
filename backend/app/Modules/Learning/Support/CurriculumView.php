<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Actions\ReadCurriculum;
use App\Modules\Learning\Models\Enrollment;

/**
 * Everything one course page needs, gathered once.
 *
 * A carrier between {@see ReadCurriculum} and
 * `CurriculumResource` and nothing more — it computes nothing of its own. The
 * reason it exists rather than an array is that the Resource has to nest a flat
 * ordered list into sections and chapters, and doing that against an untyped
 * array is where a key gets misspelled and a whole branch renders empty with no
 * error anywhere.
 */
final class CurriculumView
{
    /**
     * @param  list<Lesson>  $lessons  ordered by (section, chapter, lesson) with
     *                                 `section` and `chapter` already loaded
     * @param  array<int, LessonAccess>  $access  keyed by lesson id — the ANSWER
     *                                            for every item, including the
     *                                            ones the Resource will drop
     * @param  array<int, int>  $completedIds  a membership set, not a list to scan
     */
    public function __construct(
        public readonly Enrollment $enrollment,
        public readonly array $lessons,
        public readonly array $access,
        public readonly array $completedIds,
        public readonly int $completedCount,
        public readonly int $countableCount,
        public readonly CohortGate $cohortGate,
    ) {}
}
