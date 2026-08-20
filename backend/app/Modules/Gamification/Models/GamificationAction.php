<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\GamificationActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * What an action is worth — PLATFORM reference data (layer ب).
 *
 * Editable from /admin without a deploy (FR-002), because the initial values are
 * explicitly provisional and get tuned after a month of real behaviour (Q4). A
 * change applies from then on and NEVER recomputes past awards (FR-003): the
 * entries froze what was actually applied.
 *
 * @property int $xp
 * @property int $coins
 * @property int|null $daily_cap
 * @property bool $is_active
 */
class GamificationAction extends BaseModel
{
    /** @use HasFactory<GamificationActionFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'key',
        'name_ar',
        'xp',
        'coins',
        'daily_cap',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'coins' => 'integer',
            'daily_cap' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
