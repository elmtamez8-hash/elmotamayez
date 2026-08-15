<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Question */
class QuestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'difficulty' => $this->difficulty,
            'bloom_level' => $this->bloom_level->value,
            'concept' => $this->whenLoaded('concept', fn () => ['uuid' => $this->concept->uuid, 'name' => $this->concept->name]),
            'is_active' => $this->is_active,
            'content' => $this->content,
            'points' => $this->points,
            'explanation' => $this->explanation,
            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),
            'created_at' => $this->created_at,
        ];
    }
}
