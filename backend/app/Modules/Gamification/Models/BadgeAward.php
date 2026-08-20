<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Gamification\BadgeAwardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A badge a student holds — PLATFORM-owned (layer أ), like the progress file.
 *
 * "Once and never twice" is `unique(user_id, badge_key)`, not a check in the
 * code: the evaluator runs from a queued job that can be replayed.
 *
 * @property CarbonInterface $awarded_at
 */
class BadgeAward extends BaseModel
{
    /** @use HasFactory<BadgeAwardFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'badge_key',
        'awarded_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['awarded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
