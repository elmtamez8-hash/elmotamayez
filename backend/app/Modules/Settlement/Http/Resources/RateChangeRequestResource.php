<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Models\RateChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RateChangeRequest
 *
 * A request and what was decided about it — including the reason for a refusal,
 * because a "no" the teacher cannot learn from is a request they file again next
 * week (FR-013أ).
 */
class RateChangeRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'session_type' => $this->session_type->value,
            'session_type_label' => $this->session_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'current_amount_minor' => $this->current_amount_minor,
            'requested_amount_minor' => $this->requested_amount_minor,
            'currency' => $this->currency,
            'subject_id' => $this->subject_id,
            'grade_level' => $this->grade_level,
            'requested_at' => $this->requested_at->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,
        ];
    }
}
