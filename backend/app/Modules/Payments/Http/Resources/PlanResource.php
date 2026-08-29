<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One plan, on the buyer's screen and on the teacher's.
 *
 * ⚠️ THE PRICE IS SENT, AND THAT IS NOT A BREACH OF THE RULE THAT KEEPS MONEY
 * OFF BOTH SCREENS. That rule exists because a credit package's total is
 * `(approved settlement rate + two platform constants) × credits`, so two totals
 * solve for what the platform pays a named teacher — exactly. A plan's price is
 * a number a platform officer typed; it derives from nothing and inverts to
 * nothing, and the buyer plainly has to see what they are being asked for.
 *
 * ⚠️ `null` MEANS UNPRICED AND NEVER ZERO. The catalogue filters those out
 * before they reach a student; the teacher's own list keeps them, because «هذه
 * الباقة تنتظر تسعير المنصّة» is the whole reason nobody can buy it.
 *
 * `workspace_id` is deliberately absent — the raw tenant key never travels.
 *
 * @property-read Plan $resource
 */
class PlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'title' => $this->resource->title,
            'duration_days' => $this->resource->duration_days,
            'session_type' => $this->resource->session_type->value,
            'coverage_type' => $this->resource->coverage_type->value,
            'coverage_label' => $this->resource->coverage_type->label(),
            'coverage_uuid' => $this->resource->coverage_uuid,
            // Minor units, unformatted. The API never sends pre-formatted money:
            // a formatted string is a number the client has to parse back before
            // it can add anything up.
            'price_minor' => $this->resource->price_minor,
            'currency' => $this->resource->currency,
            'is_active' => $this->resource->is_active,
            'is_sellable' => $this->resource->isSellable(),
        ];
    }
}
