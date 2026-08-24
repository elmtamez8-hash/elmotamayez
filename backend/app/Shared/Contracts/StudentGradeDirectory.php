<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What a student officially scored with one teacher over one period.
 *
 * Exists so `Community` can build a report card without reaching into
 * `Assessments`' models, which Constitution III forbids. Same shape as
 * {@see EnrollmentDirectory}: Assessments owns exams, assignments and their
 * grading, and binds the implementation.
 *
 * ⚠️ EVERY VALUE IS `float|null`, AND NULL IS NOT ZERO. Null means "there was
 * nothing of this kind in the period" and the caller drops the component and
 * re-weights the rest (FR-053); zero means the student sat something and scored
 * nothing on it. Collapsing the two makes a student who was set no homework
 * indistinguishable from one who failed all of it, on the document their family
 * reads.
 */
interface StudentGradeDirectory
{
    /**
     * The official exam and homework percentages for one student in one
     * workspace over one period.
     *
     * ⚠️ PRACTICE ATTEMPTS ARE EXCLUDED (FR-038) — the `is_practice` column the
     * 008 rollup already excludes for its own reasons. A practice run is where a
     * student is supposed to get things wrong; counting it as a grade turns
     * revision into a penalty and makes the safest strategy never to practise.
     *
     * ⚠️ AND AN UNGRADED SUBMISSION IS EXCLUDED, NOT COUNTED AS ZERO. The late
     * party there is the teacher, exactly as in the unlock gate — a student
     * whose essay is sitting in the marking queue has not scored nothing on it.
     *
     * One call rather than two because both numbers are always wanted together
     * and both are one query each; a caller asking separately would double the
     * round trips for no reader.
     *
     * @return array{exams: float|null, homework: float|null}
     */
    public function officialGradesInPeriod(
        User $student,
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array;
}
