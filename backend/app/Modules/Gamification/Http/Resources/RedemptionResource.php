<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Resources;

use App\Modules\Gamification\Models\Redemption;
use App\Shared\Support\DisplayName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A redemption request, from either side.
 *
 * The student's name is the ABBREVIATED form even here, where the teacher already
 * knows who their students are: one rule on every surface cannot be got wrong by
 * adding a screen.
 *
 * @property-read Redemption $resource
 */
class RedemptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'status' => $this->resource->status->value,
            'status_label_ar' => $this->resource->status->labelAr(),
            'coins_spent' => $this->resource->coins_spent,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'decided_at' => $this->resource->decided_at?->toIso8601String(),
            'reward' => $this->whenLoaded('reward', fn (): array => [
                'uuid' => $this->resource->reward?->uuid,
                'title' => $this->resource->reward?->title,
                'type' => $this->resource->reward?->type->value,
            ]),
            'student_name' => $this->whenLoaded('student', fn (): string => DisplayName::forStudent($this->resource->student)),
        ];
    }
}
