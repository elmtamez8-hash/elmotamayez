<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attempt
 */
class AttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'score' => (float) $this->score,
            'max_score' => (float) $this->max_score,
            'passed' => $this->passed,
            /*
             | ⚠️ THE DURATION THIS STUDENT WAS GRANTED, AND THIS IS ITS ONLY
             | READER. It is frozen on the attempt at start (FR-054), so an
             | accommodation withdrawn afterwards does not re-time a paper
             | already sat — and without this line the column existed, was
             | written correctly, and reached nobody. It is ADVISORY: nothing in
             | this product enforces an exam timer server-side.
             |
             | On the student's own attempt payload rather than on ExamResource,
             | which every reader shares: a shared object that quietly differed
             | per reader would announce that the accommodation exists (FR-056).
             */
            'duration_minutes' => $this->duration_minutes,
            'started_at' => $this->started_at,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
