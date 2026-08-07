<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Resources;

use App\Modules\Settlement\Models\SettlementPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SettlementPeriod
 *
 * A closed window and the numbers frozen onto it.
 *
 * These are the STORED totals, never recomputed on the way out — the point of
 * freezing them is that the answer stops changing, and a Resource that summed
 * the rows again would undo that on every read.
 */
class SettlementPeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'units_count' => $this->units_count,
            'currency' => $this->currency,
            'gross_minor' => $this->gross_minor,
            'deductions_minor' => $this->deductions_minor,
            'carried_in_minor' => $this->carried_in_minor,
            'net_minor' => $this->net_minor,
            'carried_out_minor' => $this->carried_out_minor,
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
