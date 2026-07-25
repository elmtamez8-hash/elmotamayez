<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attempt */
class AttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'score' => (float) $this->score,
            'max_score' => (float) $this->max_score,
            'passed' => $this->passed,
            'started_at' => $this->started_at,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
