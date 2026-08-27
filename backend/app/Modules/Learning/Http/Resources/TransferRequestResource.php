<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Models\CohortTransferRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transfer request, as the student watches it and the teacher decides it.
 *
 * ⚠️ `decision_reason` TRAVELS TO THE STUDENT. That is the whole of FR-028ح: a
 * refusal with no reason reads as a fault and is submitted again for ever, which
 * is a queue the teacher then clears twice.
 *
 * @mixin CohortTransferRequest
 */
class TransferRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'student_reason' => $this->student_reason,
            'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'to_cohort' => $this->whenLoaded('toCohort', fn () => [
                'uuid' => $this->resource->toCohort?->uuid,
                'name' => $this->resource->toCohort?->name,
            ]),
            'from_cohort' => $this->whenLoaded('fromCohort', fn () => [
                'uuid' => $this->resource->fromCohort?->uuid,
                'name' => $this->resource->fromCohort?->name,
            ]),
            'student' => $this->whenLoaded('student', fn () => [
                'uuid' => $this->resource->student?->uuid,
                'name' => $this->resource->student?->name,
            ]),
        ];
    }
}
