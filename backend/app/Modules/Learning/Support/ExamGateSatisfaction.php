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
        $query = Attempt::query()
            ->where('exam_id', $examId)
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

        if ($gate === ExamGate::Pass) {
            $query->where('passed', true);
        }

        return $query;
    }

    /** Whether one student has. */
    public static function metBy(int $examId, ExamGate $gate, int $studentUserId): bool
    {
        return self::attempts($examId, $gate)
            ->where('student_user_id', $studentUserId)
            ->exists();
    }
}
