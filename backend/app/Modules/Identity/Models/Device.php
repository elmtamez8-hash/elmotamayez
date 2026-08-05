<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Identity\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A machine a user signs in from.
 *
 * Platform-owned: deliberately no BelongsToWorkspace. The device limit governs
 * the account as a whole, and a copy per workspace would hand a student a fresh
 * allowance every time they enrolled with another teacher.
 *
 * A teacher never reads this — not even for a student enrolled with them. Which
 * device someone studies on is not academic information.
 *
 * @property CarbonInterface|null $last_seen_at
 */
class Device extends BaseModel
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'fingerprint_hash',
        'label',
        'last_seen_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AuthSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }
}
