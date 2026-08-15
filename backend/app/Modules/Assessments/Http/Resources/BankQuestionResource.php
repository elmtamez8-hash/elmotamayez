<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Http\Controllers\AttemptController;
use App\Modules\Assessments\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bank question as its author sees it.
 *
 * ⚠️ TEACHER-FACING ONLY. It carries `is_correct` on every option and the
 * explanation — the answer key. A student sitting an exam is served the frozen
 * `attempt_items` snapshot by {@see AttemptController},
 * which is a different shape for a different reader; the two must never converge
 * into one resource with a flag, because the flag is one wrong default away from
 * publishing every answer.
 *
 * Every relation here is read through `whenLoaded`, so a caller that forgets the
 * eager load gets a thinner payload rather than a query per row.
 *
 * @mixin Question
 */
class BankQuestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'difficulty' => $this->difficulty,
            'bloom_level' => $this->bloom_level->value,
            'concept' => $this->whenLoaded('concept', fn () => [
                'uuid' => $this->concept->uuid,
                'name' => $this->concept->name,
            ]),
            'lesson' => $this->whenLoaded('lesson', fn () => $this->lesson === null ? null : [
                'uuid' => $this->lesson->uuid,
                'title' => $this->lesson->title,
            ]),
            'is_active' => $this->is_active,
            'content' => $this->content,
            'points' => $this->points,
            'explanation' => $this->explanation,
            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),
            // How many exams include it. The number a teacher checks before
            // rewriting a question, and the reason `withCount` is in the
            // controller's declared eager-load plan rather than counted per row.
            'usage_count' => $this->whenCounted('examItems'),
            'created_at' => $this->created_at,
        ];
    }
}
