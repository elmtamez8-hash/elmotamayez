<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Question */
class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'type' => $this->type,
            'difficulty' => $this->difficulty,
            'content' => $this->content,
            'points' => $this->points,
            'explanation' => $this->explanation,
            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),
            'created_at' => $this->created_at,
        ];
    }
}
