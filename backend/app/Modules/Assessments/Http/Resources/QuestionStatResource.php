<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\QuestionStat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question's wrong-answer rate, as of the last rollup.
 *
 * ⚠️ `wrong_pct` STAYS NULL, and `has_enough_data` is what the screen branches
 * on. Coalescing to zero here would be the whole of FR-013 undone in one `?? 0`:
 * a question two students sat would render as "0% wrong — nobody struggles with
 * this", which is the opposite of what is known about it.
 *
 * @mixin QuestionStat
 */
class QuestionStatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'question' => $this->whenLoaded('question', fn () => $this->question === null ? null : [
                'uuid' => $this->question->uuid,
                'content' => $this->question->content,
                'is_active' => $this->question->is_active,
                // `concept_id` is NOT NULL, so a loaded relation always resolves;
                // the guard is against the eager load being forgotten, which
                // would be one query per row on the longest list this screen has.
                'concept' => $this->question->relationLoaded('concept')
                    ? ['uuid' => $this->question->concept->uuid, 'name' => $this->question->concept->name]
                    : null,
            ]),
            'attempts_count' => $this->attempts_count,
            'wrong_count' => $this->wrong_count,
            'wrong_pct' => $this->wrong_pct,
            // Derived from the stored rate, never by re-reading the threshold:
            // the floor is one platform setting, and asking it once per row is
            // an N+1 against a cache that exists to be asked once.
            'has_enough_data' => $this->wrong_pct !== null,
            'computed_at' => $this->computed_at,
        ];
    }
}
