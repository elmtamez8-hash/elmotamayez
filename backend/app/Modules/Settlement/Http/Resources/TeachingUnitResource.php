<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Models\TeachingUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeachingUnit
 *
 * What a teacher may see about one unit of their own work.
 *
 * Everything here is about the teacher's side. There is no field for what the
 * student paid, what the platform kept, or what the sale price was — not
 * filtered out, not present (FR-018 · FR-003). TeacherFieldAllowlist asserts
 * that from the outside so this list cannot quietly grow.
 */
class TeachingUnitResource extends JsonResource
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
            'basis' => $this->basis->value,
            // Minor units with the currency beside them, formatted by the client.
            // Never a float, and never a formatted string the client would have
            // to parse back to do arithmetic on.
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'frozen_seats' => $this->frozen_seats,
            'pending_reason' => $this->pending_reason,
            'recording_fault' => $this->recording_fault,
            'delivered_at' => $this->delivered_at->toIso8601String(),
            'accrued_at' => $this->accrued_at?->toIso8601String(),
        ];
    }
}
