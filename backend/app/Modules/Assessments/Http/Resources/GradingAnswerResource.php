<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\GradingRecord;
use App\Modules\Assessments\Models\RubricCriterion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One essay, its mark scheme, and whatever has already been awarded on it.
 *
 * ⚠️ `points` IS THE SNAPSHOT'S, not the live question's. The grader must be
 * shown the ceiling the student was actually sitting against — a question raised
 * from 5 to 10 after the paper was handed in would otherwise let the marker
 * award marks the Action then refuses (FR-004).
 *
 * @mixin Answer
 */
class GradingAnswerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $question = $this->question;

        return [
            'uuid' => $this->uuid,
            'question' => $question === null ? null : [
                'uuid' => $question->uuid,
                'content' => $question->content,
                'explanation' => $question->explanation,
            ],
            'answer_text' => $this->answer_text,
            'points_possible' => (float) ($this->getAttribute('points_possible') ?? 0),
            'points_awarded' => (float) $this->points,
            'is_graded' => $this->graded_at !== null,
            'graded_at' => $this->graded_at,
            'grading_version' => (int) $this->grading_version,
            'criteria' => $this->criteria(),
            'marks' => $this->marks(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function criteria(): array
    {
        $question = $this->question;

        if ($question === null || ! $question->relationLoaded('rubricCriteria')) {
            return [];
        }

        $rows = [];

        foreach ($question->rubricCriteria as $criterion) {
            /** @var RubricCriterion $criterion */
            $rows[] = [
                'id' => (int) $criterion->getKey(),
                'label' => $criterion->label,
                'max_points' => (float) $criterion->max_points,
            ];
        }

        return $rows;
    }

    /**
     * What the current generation of grading awarded.
     *
     * Superseded generations are deliberately not sent: the screen is for
     * marking, and a marker shown four versions of the same criterion has to
     * work out which one is live before they can start.
     *
     * @return list<array<string, mixed>>
     */
    private function marks(): array
    {
        if (! $this->relationLoaded('gradingRecords')) {
            return [];
        }

        $rows = [];

        foreach ($this->gradingRecords as $record) {
            /** @var GradingRecord $record */
            if ((int) $record->grading_version !== (int) $this->grading_version) {
                continue;
            }

            $rows[] = [
                'criterion_id' => $record->rubric_criterion_id === null ? null : (int) $record->rubric_criterion_id,
                'points' => (float) $record->points,
                'comment' => $record->comment,
                'revision_reason' => $record->revision_reason,
            ];
        }

        return $rows;
    }
}
