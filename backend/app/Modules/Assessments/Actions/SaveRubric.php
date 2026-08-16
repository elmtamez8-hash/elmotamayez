<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\GradingRecord;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\RubricCriterion;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the mark scheme for one essay question (FR-028).
 *
 * ⚠️ THE SUM IS ENFORCED HERE, NOT IN THE FORM REQUEST. A rule in the request
 * guards one door: the seeder, Filament and any later import reach this Action
 * without passing it, and a rubric adding up to more than the question is worth
 * hands a student 120% of a paper — which then leaves the attempt scoring above
 * a passing threshold it never met.
 */
class SaveRubric extends Action
{
    use LogsActivity;

    /**
     * @param  array<int, array{label: string, max_points: float|int|string, order?: int}>  $criteria
     * @return Collection<int, RubricCriterion>
     *
     * @throws DomainException when the question takes no rubric, the criteria overflow it,
     *                         or somebody has already graded against the current scheme
     */
    public function handle(Question $question, array $criteria): Collection
    {
        if (! $question->isEssay()) {
            throw new DomainException('Only an essay question is graded against a rubric.');
        }

        $total = 0.0;

        foreach ($criteria as $criterion) {
            $points = (float) $criterion['max_points'];

            if ($points <= 0) {
                throw new DomainException('A criterion must be worth something.');
            }

            $total += $points;
        }

        if ($total > (float) $question->points) {
            throw new DomainException('The criteria add up to more than the question is worth.');
        }

        /*
        | ⚠️ A SCHEME THAT HAS BEEN GRADED AGAINST IS FROZEN, and the refusal is
        | the honest answer. `grading_records` points at a criterion by id;
        | replacing the set leaves every past record pointing at a row that no
        | longer exists, so the grading history renders as marks awarded on
        | nameless criteria — a teacher's own record of why they gave 6/10,
        | erased by a typo fix. Changing a grade has a door of its own
        | ({@see ReviseGrade}); changing the scheme underneath one does not.
        */
        if ($this->alreadyGraded($question)) {
            throw new DomainException('This question has already been graded against its current rubric.');
        }

        return DB::transaction(function () use ($question, $criteria): Collection {
            $question->rubricCriteria()->delete();

            foreach ($criteria as $index => $criterion) {
                RubricCriterion::create([
                    'workspace_id' => $question->workspace_id,
                    'question_id' => $question->getKey(),
                    'label' => $criterion['label'],
                    'max_points' => (float) $criterion['max_points'],
                    'order' => $criterion['order'] ?? $index,
                ]);
            }

            $this->logActivity('rubric.saved', $question, [
                'criteria' => count($criteria),
            ]);

            return $question->rubricCriteria()->get();
        });
    }

    private function alreadyGraded(Question $question): bool
    {
        $criterionIds = $question->rubricCriteria()->pluck('id');

        if ($criterionIds->isEmpty()) {
            return false;
        }

        return GradingRecord::query()
            ->whereIn('rubric_criterion_id', $criterionIds)
            ->exists();
    }
}
