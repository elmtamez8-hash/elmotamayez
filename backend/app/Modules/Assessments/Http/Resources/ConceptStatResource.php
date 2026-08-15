<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\ConceptStat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A concept's wrong-answer rate — overall, or within one lesson.
 *
 * `lesson` is null on the overall row, which is the honest rendering of
 * `lesson_id = 0`: that row is about no single lesson. See {@see ConceptStat}.
 *
 * Same rule as {@see QuestionStatResource}: a null rate travels as null.
 *
 * @mixin ConceptStat
 */
class ConceptStatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'concept' => $this->whenLoaded('concept', fn () => $this->concept === null ? null : [
                'uuid' => $this->concept->uuid,
                'name' => $this->concept->name,
            ]),
            'is_overall' => $this->lesson_id === ConceptStat::OVERALL,
            'lesson' => $this->whenLoaded('lesson', fn () => $this->lesson === null ? null : [
                'uuid' => $this->lesson->uuid,
                'title' => $this->lesson->title,
            ]),
            'attempts_count' => $this->attempts_count,
            'wrong_count' => $this->wrong_count,
            'wrong_pct' => $this->wrong_pct,
            'has_enough_data' => $this->wrong_pct !== null,
            'computed_at' => $this->computed_at,
        ];
    }
}
