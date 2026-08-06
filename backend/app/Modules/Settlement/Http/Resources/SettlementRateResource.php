<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Models\SettlementRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SettlementRate
 *
 * The teacher's own rate, and only ever their own.
 *
 * There is no field here for the sale price, the platform's fee or the margin —
 * not filtered, absent (FR-021ب · FR-018). The amount is a settlement rate: what
 * the platform pays for one unit, which is a different number from what a
 * student pays and deliberately unconnected to it.
 */
class SettlementRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'session_type' => $this->session_type->value,
            'session_type_label' => $this->session_type->label(),
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            // Null means "all subjects" / "all grades" — the simple default that
            // most teachers will never move away from (FR-014أ).
            'subject_id' => $this->subject_id,
            'grade_level' => $this->grade_level,
            'effective_from' => $this->effective_from->toIso8601String(),
        ];
    }
}
