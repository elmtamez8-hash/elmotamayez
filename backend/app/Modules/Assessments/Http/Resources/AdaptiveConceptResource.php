<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of «what you may practise», already shaped by the Action.
 *
 * A Resource over an array rather than a model, and deliberately thin: the shape
 * comes out of ONE grouped query per teacher, so there is no model to hydrate and
 * hydrating one per row would be the N+1 the Action exists to avoid. It is a
 * Resource at all because every response in this module goes through one — that
 * is what `AssessmentExposureTest` walks, and a payload that skips the layer is a
 * payload nothing checks.
 *
 * @property array<string, mixed> $resource
 */
class AdaptiveConceptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'teacher' => $row['teacher'],
            'question_count' => (int) $row['question_count'],
            // Stated, because mastery is measured AT it — a screen that assumed
            // `hard` would show a bar the student cannot reach in a concept whose
            // questions stop at `medium`.
            'ceiling_difficulty' => (string) $row['ceiling_difficulty'],
            'mastered_at' => $row['mastered_at'],
        ];
    }
}
