<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\GradingRecord;
use App\Modules\Assessments\Models\RubricCriterion;
use DomainException;

/**
 * What a set of marks means, and where it is written.
 *
 * ⚠️ ONE PLACE FOR BOTH DOORS. Grading and revising are two Actions with two
 * different claims, but the arithmetic they perform is identical — and a second
 * copy of "do these marks fit inside the question" is a second copy that stops
 * agreeing with the first the day a rubric rule changes. The claims stay in the
 * Actions, where they differ; the meaning of a mark lives here, where it does not.
 *
 * @phpstan-type Mark array{criterion_id?: int|null, points: float|int|string, comment?: string|null}
 */
class EssayMarks
{
    /**
     * What this question was worth on THIS paper.
     *
     * The snapshot, never the live question: a teacher who raised a question
     * from 5 to 10 after the exam was sat must not thereby cap the class at half
     * marks, and one who lowered it must not let an old paper score above its
     * own total (FR-004).
     */
    public function ceilingFor(Answer $answer): float
    {
        $item = AttemptItem::query()
            ->where('attempt_id', $answer->attempt_id)
            ->where('question_id', $answer->question_id)
            ->first();

        return $item === null ? 0.0 : (float) $item->points;
    }

    /**
     * Add the marks up, refusing anything that does not fit.
     *
     * @param  array<int, Mark>  $marks
     *
     * @throws DomainException
     */
    public function total(Answer $answer, array $marks, float $ceiling): float
    {
        if ($marks === []) {
            throw new DomainException('A grade needs at least one mark.');
        }

        $criteria = RubricCriterion::query()
            ->where('question_id', $answer->question_id)
            ->get()
            ->keyBy('id');

        $awarded = 0.0;

        foreach ($marks as $mark) {
            $points = (float) $mark['points'];

            if ($points < 0) {
                throw new DomainException('A mark cannot be negative.');
            }

            $criterionId = $mark['criterion_id'] ?? null;

            if ($criterionId !== null) {
                $criterion = $criteria->get($criterionId);

                if ($criterion === null) {
                    throw new DomainException('That criterion does not belong to this question.');
                }

                if ($points > (float) $criterion->max_points) {
                    throw new DomainException('A mark cannot exceed what its criterion is worth.');
                }
            }

            $awarded += $points;
        }

        if ($awarded > $ceiling) {
            throw new DomainException('The marks add up to more than the question is worth.');
        }

        return $awarded;
    }

    /**
     * Append one generation of marks. Never updates: the table is the history.
     *
     * @param  array<int, Mark>  $marks
     */
    public function write(Answer $answer, User $grader, array $marks, int $version, ?int $revisionOf = null, ?string $reason = null): void
    {
        foreach ($marks as $mark) {
            GradingRecord::create([
                'workspace_id' => $answer->workspace_id,
                'answer_id' => $answer->getKey(),
                'rubric_criterion_id' => $mark['criterion_id'] ?? null,
                'points' => (float) $mark['points'],
                // FR-029 lives here rather than in a column on the answer: a
                // rubric grade carries a remark per criterion, which is what the
                // student needs to know WHERE they lost the mark, and a grade
                // with no rubric is exactly one record, so its comment is the
                // comment on the answer.
                'comment' => $mark['comment'] ?? null,
                'graded_by' => $grader->getKey(),
                'revision_of' => $revisionOf,
                'revision_reason' => $reason,
                'grading_version' => $version,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * ⚠️ FULL MARKS OR IT IS WRONG, on the same rule the machine already applies:
     * a multiple-select answered two-thirds right is stored `false`, because
     * `matchesSnapshot()` demands the whole set. An essay given 9/10 therefore
     * stays in the mistake notebook, and that is the intended reading of FR-019 —
     * the notebook is what the student has not finished learning, not a list of
     * papers they failed. Two consumers inherit this line: the notebook and the
     * item-analysis rollup.
     */
    public function isCorrect(float $awarded, float $ceiling): bool
    {
        return $ceiling > 0 && $awarded >= $ceiling;
    }
}
