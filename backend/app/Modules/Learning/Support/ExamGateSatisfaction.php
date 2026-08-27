<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Courses\Enums\ExamGate;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Has this student already answered what the item asks?" — asked in one place.
 *
 * Three callers need this predicate: the sequential gate, the listener that ticks
 * the item off when the exam is submitted, and the backfill that catches students
 * who sat the exam BEFORE the teacher placed it. Written three times it would be
 * three definitions of "attempted", and the first one to drift decides whether a
 * course can be finished.
 *
 * **Attempted means SUBMITTED.** A started-and-abandoned attempt is a row that
 * exists because the student opened the page; counting it would make the weaker
 * gate no gate at all.
 */
final class ExamGateSatisfaction
{
    /**
     * The attempts that answer this gate — any student's.
     *
     * @return Builder<Attempt>
     */
    public static function attempts(int $examId, ExamGate $gate): Builder
    {
        $query = self::answering([$examId]);

        if ($gate === ExamGate::Pass) {
            $query->where('passed', true);
        }

        return $query;
    }

    /**
     * The sittings that can answer a gate at all, for any of these exams.
     *
     * ⚠️ THE THREE CONDITIONS ARE SPELLED HERE AND NOWHERE ELSE. Both the
     * single-exam form above and the bulk form below read them from this one
     * method, so a fourth condition added tomorrow reaches every caller. The bulk
     * form was added for the curriculum screen, and had it re-spelled "submitted,
     * non-practice" beside the tree walk, the gate a student is shown on the
     * curriculum and the gate enforced at the lesson door would be free to drift
     * apart — which is the defect the whole extraction exists to prevent.
     *
     * @param  list<int>  $examIds
     * @return Builder<Attempt>
     */
    private static function answering(array $examIds): Builder
    {
        return Attempt::query()
            ->whereIn('exam_id', $examIds)
            ->whereNotNull('submitted_at')
            /*
            | ⚠️ AND A PRACTICE SITTING IS NOT AN ANSWER. The same exam may be sat
            | officially and again for revision (spec 008, FR-025), and both rows
            | carry the same `exam_id` — so without this line a student unlocks
            | the next chapter, and under `ExamGate::Pass` records a pass, by
            | setting themselves the paper and marking it instantly with the
            | explanations in front of them.
            */
            ->where('is_practice', false);
    }

    /**
     * Which of these gates one student has already answered — one query for the
     * whole course (`SC-004`).
     *
     * The map is keyed by exam because ONE exam may be placed twice in a tree
     * under two different gates: an `attempt` item early on and a `pass` item
     * before the final chapter is a shape a teacher can build, and answering it
     * as a single boolean per exam would open the second from the first.
     *
     * @param  array<int, list<ExamGate>>  $gatesByExamId
     * @return array<int, array<string, true>> exam id → gate value → satisfied
     */
    public static function satisfiedFor(int $studentUserId, array $gatesByExamId): array
    {
        if ($gatesByExamId === []) {
            return [];
        }

        $submitted = [];
        $passed = [];

        self::answering(array_map(intval(...), array_keys($gatesByExamId)))
            ->where('student_user_id', $studentUserId)
            ->select(['exam_id', 'passed'])
            ->each(function (Attempt $attempt) use (&$submitted, &$passed): void {
                $examId = (int) $attempt->exam_id;
                $submitted[$examId] = true;

                if ($attempt->passed) {
                    $passed[$examId] = true;
                }
            });

        $out = [];

        foreach ($gatesByExamId as $examId => $gates) {
            foreach ($gates as $gate) {
                // The same one-line distinction `attempts()` makes above, and the
                // reason both live in this file rather than at their call sites.
                $met = $gate === ExamGate::Pass
                    ? isset($passed[$examId])
                    : isset($submitted[$examId]);

                if ($met) {
                    $out[$examId][$gate->value] = true;
                }
            }
        }

        return $out;
    }

    /** Whether one student has. */
    public static function metBy(int $examId, ExamGate $gate, int $studentUserId): bool
    {
        return self::attempts($examId, $gate)
            ->where('student_user_id', $studentUserId)
            ->exists();
    }
}
