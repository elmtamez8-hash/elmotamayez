<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Notifications\ContactVerificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Timestamps restated: Larastan reads them as plain `timestamp` from the
 * migration and does not see casts().
 *
 * @property string $channel
 * @property string $contact_value
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon|null $expires_at
 * @property Carbon|null $verified_at
 */
class ContactVerification extends BaseModel
{
    /** @use HasFactory<ContactVerificationFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'channel',
        'contact_value',
        'code_hash',
        'attempts',
        'expires_at',
        'verified_at',
    ];

    /**
     * The hash never leaves the model. Even an accidental toArray() on a debug
     * route must not put a verification code within reach.
     *
     * @var list<string>
     */
    protected $hidden = ['code_hash'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::from($this->channel);
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < (int) config('notifications.verification.max_attempts');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
