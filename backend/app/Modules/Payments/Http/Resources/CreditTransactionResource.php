<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger line.
 *
 * `source_type` and `source_id` stay on the server: they are internal keys, and
 * `performed_by` names a member of staff. The student is told what moved, which
 * way, when, and why — the reason is the field that makes a correction readable
 * instead of alarming.
 *
 * @property CreditTransaction $resource
 */
class CreditTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'type' => $this->resource->type->value,
            'type_label' => $this->resource->type->label(),
            'credits' => $this->resource->credits,
            'reason' => $this->resource->reason,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
