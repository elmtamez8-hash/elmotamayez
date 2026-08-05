<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Identity\Support\SessionEndReason;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Identity\AuthSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in, on one device.
 *
 * Named AuthSession on purpose: `Session` would collide with the framework's own
 * and, from the next phase, with a teaching session (ClassSession).
 *
 * The row survives being ended — it IS the audit log required by FR-026. A
 * second events table would only restate what is already here.
 *
 * @property string $status
 * @property SessionEndReason|null $ended_reason
 * @property CarbonInterface|null $last_active_at
 * @property CarbonInterface|null $ended_at
 * @property-read Device $device device_id is NOT NULL, so it always resolves
 */
class AuthSession extends BaseModel
{
    /** @use HasFactory<AuthSessionFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'user_id',
        'device_id',
        'token_id',
        'status',
        'ended_reason',
        'ip_hash',
        'last_active_at',
        'ended_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'ended_reason' => SessionEndReason::class,
            'last_active_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @param Builder<AuthSession> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
