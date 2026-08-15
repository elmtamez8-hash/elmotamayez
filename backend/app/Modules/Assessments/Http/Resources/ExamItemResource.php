<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\ExamItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bank question's place in one exam.
 *
 * `points` is the EFFECTIVE worth, resolved here rather than left to the client
 * to compute from `points_override ?? question.points` — a client that gets that
 * fallback wrong shows a teacher a total the grader will not agree with.
 *
 * @mixin ExamItem
 */
class ExamItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'order' => $this->order,
            'points_override' => $this->points_override,
            'points' => $this->effectivePoints(),
            'question' => BankQuestionResource::make($this->whenLoaded('question')),
        ];
    }
}
