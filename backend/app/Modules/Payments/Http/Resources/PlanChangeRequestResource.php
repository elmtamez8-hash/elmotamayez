<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\PlanChangeRequest;
use App\Modules\Payments\Support\PlanShape;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One change request, on the teacher's own screen (٠٣٦).
 *
 * ⚠️ BOTH SIDES TRAVEL AS SENTENCES, NOT AS RAW COLUMNS. «من ١٢ حصّة إلى شهر
 * واحد» is the whole content of this row for the person reading it, and deriving
 * it in TypeScript would be a second spelling of `PlanShape` — the defect this
 * spec spent a whole requirement on.
 *
 * ⚠️ AND THE PRICES TRAVEL IN MINOR UNITS, UNFORMATTED. The API never sends
 * pre-formatted money: a formatted string is a number the client has to parse
 * back before it can add anything up.
 *
 * `workspace_id` is deliberately absent — the raw tenant key never travels.
 *
 * @property-read PlanChangeRequest $resource
 */
class PlanChangeRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'plan_title' => (string) ($this->resource->plan->title ?? '—'),
            'current_shape' => PlanShape::describe(
                $this->resource->current_duration_days,
                $this->resource->current_session_count,
            ),
            'requested_shape' => PlanShape::describe(
                $this->resource->requested_duration_days,
                $this->resource->requested_session_count,
            ),
            'current_coverage_label' => $this->resource->current_coverage_type->label(),
            'requested_coverage_label' => $this->resource->requested_coverage_type->label(),
            'current_price_minor' => $this->resource->current_price_minor,
            'requested_price_minor' => $this->resource->requested_price_minor,
            'currency' => (string) ($this->resource->plan->currency ?? 'QAR'),
            'reason' => $this->resource->reason,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'decision_reason' => $this->resource->decision_reason,
            'requested_at' => $this->resource->requested_at?->toIso8601String(),
            'decided_at' => $this->resource->decided_at?->toIso8601String(),
        ];
    }
}
