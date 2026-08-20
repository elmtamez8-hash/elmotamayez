<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Gamification\LeaderboardEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ranking row — DERIVED, never a source (FR-025 · SC-011).
 *
 * Nothing writes here except the rollup job and the rebuild Action; delete the
 * whole table and one command restores it from `award_entries` with identical
 * ordering. That property is what makes an indexed table an acceptable answer to
 * a question that "obviously" wanted a Redis sorted set.
 *
 * @property int $points
 * @property int $level_band
 * @property int $rank
 */
class LeaderboardEntry extends BaseModel
{
    /** @use HasFactory<LeaderboardEntryFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'scope_key',
        'period_key',
        'user_id',
        'points',
        'level_band',
        'rank',
        'run_stamp',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'level_band' => 'integer',
            'rank' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
