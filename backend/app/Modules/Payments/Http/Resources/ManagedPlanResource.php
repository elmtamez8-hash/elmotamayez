<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One plan, on the TEACHER's own screen (`/manage/plans`).
 *
 * ⛔ NO PRICE, AND NO CURRENCY (owner decision 2026-09-29). The platform sets a
 * plan's price, and the teacher's screen does not show it — `PlanResource`, the
 * buyer's shape, keeps it because a student has to see what they are asked to
 * pay. Two classes rather than a flag on one: a flag is a condition somebody
 * «simplifies» away, and then every teacher reads the platform's number again.
 *
 * ⚠️ `is_priced` IS WHAT THE SCREEN NEEDS FROM THE PRICE, AND ALL OF IT.
 * «تنتظر تسعير المنصّة» and «edit versus ask for a change» both turn on whether
 * a number exists, never on what it is.
 *
 * `workspace_id` is deliberately absent — the raw tenant key never travels.
 *
 * @property-read Plan $resource
 */
class ManagedPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'title' => $this->resource->title,
            // Both shapes travel, and exactly one of them is set — see PlanResource.
            'duration_days' => $this->resource->duration_days,
            'session_count' => $this->resource->session_count,
            'session_type' => $this->resource->session_type->value,
            'coverage_type' => $this->resource->coverage_type->value,
            'coverage_label' => $this->resource->coverage_type->label(),
            'coverage_uuid' => $this->resource->coverage_uuid,
            'is_priced' => $this->resource->price_minor !== null,
            'is_active' => $this->resource->is_active,
            'is_sellable' => $this->resource->isSellable(),
        ];
    }
}
