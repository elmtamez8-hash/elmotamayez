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

    /**
     * The one contact detail this account has PROVEN on this channel, or null.
     *
     * ⚠️ THIS AND NEVER `users.phone` (spec 020, FR-003). That column is a free
     * string anyone can type, nobody confirmed, and a typo in it is a message
     * about a child sent to a stranger. This table exists precisely so an
     * external channel has a different question to ask.
     *
     * At most one row survives per (user, channel): RequestContactVerification
     * deletes everything prior before issuing, so moving your number cannot
     * leave the old one verified and still receiving.
     */
    public static function verifiedValueFor(User $user, NotificationChannel $channel): ?string
    {
        return self::query()
            ->where('user_id', $user->getKey())
            ->where('channel', $channel->value)
            ->whereNotNull('verified_at')
            ->value('contact_value');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
