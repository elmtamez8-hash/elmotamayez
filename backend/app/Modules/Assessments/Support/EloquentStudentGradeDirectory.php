<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Submission;
use App\Shared\Contracts\StudentGradeDirectory;
use Carbon\CarbonImmutable;

/**
 * Assessments' answer to "what did this student officially score?".
 *
 * Queries run without the workspace scope on purpose: the report card is built
 * by a platform job that walks every workspace, and the guard is the explicit
 * `workspace_id` argument plus the student's own id — stricter than the scope,
 * not looser, the same reasoning as `EloquentSessionAttendanceDirectory`.
 */
class EloquentStudentGradeDirectory implements StudentGradeDirectory
{
    /** @return array{exams: float|null, homework: float|null} */
    public function officialGradesInPeriod(
        User $student,
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        return [
            'exams' => $this->exams($student, $workspaceId, $from, $to),
            'homework' => $this->homework($student, $workspaceId, $from, $to),
        ];
    }

    private function exams(User $student, int $workspaceId, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        /** @var object{scored: string|float|null, possible: string|float|null}|null $row */
        $row = Attempt::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $student->getKey())
            ->where('is_practice', false)
            ->where('status', Attempt::STATUS_GRADED)
            ->whereNotNull('exam_id')
            ->whereBetween('submitted_at', $this->window($from, $to))
            ->selectRaw('SUM(score) as scored, SUM(max_score) as possible')
            ->first();

        return $this->percentage($row?->scored, $row?->possible);
    }

    private function homework(User $student, int $workspaceId, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        /** @var object{scored: string|float|null, possible: string|float|null}|null $row */
        $row = Submission::query()
            ->withoutWorkspaceScope()
            ->where('submissions.workspace_id', $workspaceId)
            ->where('submissions.student_user_id', $student->getKey())
            // ⚠️ GRADED ONLY. A submission still in the marking queue is not a
            // zero — the late party is the teacher, and the student's grade must
            // not fall while they wait for it.
            ->whereNotNull('submissions.graded_at')
            ->whereNotNull('submissions.score')
            ->whereBetween('submissions.graded_at', $this->window($from, $to))
            ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
            ->selectRaw('SUM(submissions.score) as scored, SUM(assignments.points) as possible')
            ->first();

        return $this->percentage($row?->scored, $row?->possible);
    }

    /**
     * ⚠️ THE UPPER BOUND IS THE START OF THE NEXT DAY, NOT `<= period_end`. The
     * period bounds are DATES and both columns here are timestamps, so a `<=`
     * against the closing date binds midnight and silently drops everything
     * marked on the last day of the term — the same boundary that already cost
     * `FreezePeriod::covering()` and a settlement close their own fixes.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->startOfDay(), $to->startOfDay()->addDay()];
    }

    private function percentage(string|float|null $scored, string|float|null $possible): ?float
    {
        // No rows at all: SUM returns null, which is the "nothing of this kind
        // happened" signal the whole contract turns on. A possible total of zero
        // is the same answer reached differently — nothing was worth any points,
        // so there is no percentage to state.
        if ($possible === null || (float) $possible <= 0.0) {
            return null;
        }

        return round((float) $scored / (float) $possible * 100, 2);
    }
}
