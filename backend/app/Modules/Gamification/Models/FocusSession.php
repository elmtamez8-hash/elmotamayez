<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Gamification\Enums\FocusSessionStatus;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Gamification\FocusSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A study session — PLATFORM-owned (layer أ).
 *
 * ⚠️ THIS TABLE IS THE ONLY COPY OF "is this student focusing?". There is no
 * cache key beside it: Shared\Contracts\FocusState reads `status = 'running'`
 * here. A cache key was the first design and every hazard it needed warning
 * about — a missed close leaving the mute stuck on for ever — existed only
 * because there would have been two copies of one fact.
 *
 * @property FocusSessionStatus $status
 * @property int $planned_minutes
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $ended_at
 */
class FocusSession extends BaseModel
{
    /** @use HasFactory<FocusSessionFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'planned_minutes',
        'started_at',
        'ended_at',
        'status',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => FocusSessionStatus::class,
            'planned_minutes' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
