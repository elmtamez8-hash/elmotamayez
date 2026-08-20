<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Resources;

use App\Modules\Gamification\Models\StudentProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The student's own gamification state (FR-041).
 *
 * ⚠️ A RESOURCE RUNS ONCE PER ROW, SO A QUERY INSIDE ONE IS AN N+1 BY
 * CONSTRUCTION — a lesson this repository has already paid for once, in
 * ClassSessionResource. Three lookups were waiting to be written here:
 *
 *   - a badge's Arabic name, per badge. `badge_key` is TEXT and not a foreign
 *     key (a retired badge must stay readable on the profile), so `with()`
 *     cannot help — the catalogue is loaded once and keyed by slug instead.
 *   - the teacher's name, per purse. That one IS a relation, so it is eager
 *     loaded.
 *   - the level's Arabic name and the next threshold.
 *
 * All three are resolved by the controller and handed in.
 *
 * ⚠️ AND THERE IS NO `coins_total`. No sum across teachers is correct — a purse
 * belongs to one teacher — so a displayed total would promise exactly what the
 * shop refuses on the first attempt.
 *
 * @property-read StudentProgress $resource
 */
class ProgressResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $context  level name, next threshold, badges, purses
     */
    public function __construct(StudentProgress $resource, private readonly array $context)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'xp' => $this->resource->xp,
            'level' => $this->resource->level,
            'level_name_ar' => $this->context['level_name_ar'],
            'next_level_xp' => $this->context['next_level_xp'],
            'current_streak' => $this->resource->current_streak,
            'best_streak' => $this->resource->best_streak,
            'shields' => $this->resource->shield_count,
            'badges' => $this->context['badges'],
            'coin_balances' => $this->context['coin_balances'],
        ];
    }
}
