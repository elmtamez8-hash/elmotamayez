<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Resources;

use App\Modules\Gamification\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shop item.
 *
 * ⚠️ NO MONEY IN ANY FIELD. A reward's cost to the teacher is their business and
 * a credit's price is solvable from two package sizes — the rule spec 006 wrote
 * for balances applies here unchanged. What travels is a price in COINS, which is
 * not money and cannot be converted to any (FR-036).
 *
 * @property-read Reward $resource
 */
class RewardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'title' => $this->resource->title,
            'price_coins' => $this->resource->price_coins,
            'stock' => $this->resource->stock,
            'type' => $this->resource->type->value,
            'type_label_ar' => $this->resource->type->labelAr(),
            'is_active' => $this->resource->is_active,
            /*
            | The cap is shown; how much of it is left is NOT. "Three left this
            | month" is a race the student cannot win and a number that is stale
            | the moment it renders — and the claim is atomic precisely so nobody
            | has to reason about it. The refusal, when it comes, says why.
            */
            'monthly_cap' => $this->resource->monthly_cap,
        ];
    }
}
