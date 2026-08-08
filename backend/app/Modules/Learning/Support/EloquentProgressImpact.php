<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Contracts\ProgressImpact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Learning's answer to "who does this publish move, and by how much?".
 *
 * Everything here is the arithmetic the product already runs, asked ahead of
 * time: `CourseProgress::percentage()` is the formula `MarkLessonComplete`
 * stores with, and `ExamGateSatisfaction` is the predicate the backfill listener
 * credits by. `SC-018` is the promise that the preview and the outcome are the
 * same number, and that only holds while there is one of each.
 *
 * Answers with a count and the two extremes rather than a roster. `FR-049` asks
 * how many students, the contract adds how much they move, and neither needs a
 * list of names on an authoring screen.
 */
class EloquentProgressImpact implements ProgressImpact
{
    /**
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @param  list<array{lesson_id: int, exam_id: int, gate: string}>  $openingExamItems
     * @return array{affected: int, drop: int, gain: int}
     */
    public function of(int $courseId, array $before, array $after, array $openingExamItems): array
    {
        $enrollments = Enrollment::query()
            ->where('course_id', $courseId)
            // Active and completed only. A cancelled or expired enrolment is not
            // a percentage anyone is looking at.
            ->whereIn('status', ['active', 'completed'])
            ->get(['id', 'student_user_id', 'status']);

        if ($enrollments->isEmpty()) {
            return ['affected' => 0, 'drop' => 0, 'gain' => 0];
        }

        /** @var list<int> $enrollmentIds */
        $enrollmentIds = $enrollments->map(fn (Enrollment $e): int => (int) $e->getKey())->all();

        $completed = $this->completedByEnrollment(
            $enrollmentIds,
            array_values(array_unique([...$before, ...$after])),
        );

        $this->creditPendingBackfill($openingExamItems, $enrollments, $completed);

        $beforeSet = array_flip($before);
        $afterSet = array_flip($after);

        $affected = 0;
        $drop = 0;
        $gain = 0;

        foreach ($enrollments as $enrollment) {
            $done = $completed[(int) $enrollment->getKey()] ?? [];

            $was = CourseProgress::percentage(count(array_intersect_key($done, $beforeSet)), count($before));
            $now = CourseProgress::percentage(count(array_intersect_key($done, $afterSet)), count($after));

            if ($was === $now) {
                continue;
            }

            $affected++;
            $drop = min($drop, $now - $was);
            $gain = max($gain, $now - $was);
        }

        return ['affected' => $affected, 'drop' => $drop, 'gain' => $gain];
    }

    /**
     * Completed lesson ids per enrolment, in one query rather than one per row.
     *
     * @param  list<int>  $enrollmentIds
     * @param  list<int>  $lessonIds
     * @return array<int, array<int, true>>
     */
    private function completedByEnrollment(array $enrollmentIds, array $lessonIds): array
    {
        if ($lessonIds === []) {
            return [];
        }

        $rows = DB::table('lesson_progress')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('lesson_id', $lessonIds)
            ->where('status', 'completed')
            ->get(['enrollment_id', 'lesson_id']);

        $completed = [];

        foreach ($rows as $row) {
            $completed[(int) $row->enrollment_id][(int) $row->lesson_id] = true;
        }

        return $completed;
    }

    /**
     * Credits what opening an exam item is about to credit.
     *
     * Without this the preview is wrong for every student who sat the exam before
     * the teacher placed it — which is the ordinary case, not an edge one: an
     * exam is answered from its own page, often weeks before anyone decides where
     * it belongs in the tree. `CompleteExamLessonsAlreadyAnswered` writes their
     * progress rows the moment the item is published, so a preview that read
     * `lesson_progress` as it stands would report a drop that never happens.
     *
     * Same predicate, same gate default, same "active enrolments only" as that
     * listener — read as a SET per exam item rather than as a question per
     * student, which would be one query each.
     *
     * @param  list<array{lesson_id: int, exam_id: int, gate: string}>  $openingExamItems
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  array<int, array<int, true>>  $completed
     */
    private function creditPendingBackfill(array $openingExamItems, Collection $enrollments, array &$completed): void
    {
        foreach ($openingExamItems as $item) {
            $gate = ExamGate::tryFrom($item['gate']) ?? ExamGate::Attempt;

            $answered = array_flip(
                ExamGateSatisfaction::attempts($item['exam_id'], $gate)
                    ->distinct()
                    ->pluck('student_user_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            );

            foreach ($enrollments as $enrollment) {
                // Active only, because that is who the listener credits — it
                // refuses a cancelled or expired enrolment, and a preview that
                // credited one would promise a percentage nobody receives.
                if ($enrollment->status !== 'active') {
                    continue;
                }

                if (isset($answered[(int) $enrollment->student_user_id])) {
                    $completed[(int) $enrollment->getKey()][$item['lesson_id']] = true;
                }
            }
        }
    }
}
