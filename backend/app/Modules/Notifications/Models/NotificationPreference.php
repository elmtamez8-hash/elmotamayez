<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Notifications\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's channel choice for one notification type.
 *
 * Moved here from Identity: it was two booleans about a person, it is now a
 * mapping about notifications, and it is read on every dispatch.
 *
 * A missing row is not "no channels" — it means the user never expressed an
 * opinion, so the type's defaults apply (FR-028). That is why the table stays
 * empty for most accounts instead of being seeded with a row per type per user.
 *
 * Platform-owned: no BelongsToWorkspace. A student muting exam results means all
 * of them, not the ones from one teacher.
 *
 * @property string $type
 * @property array<int, string> $channels
 * @property int|null $digest_window_minutes
 */
class NotificationPreference extends BaseModel
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'type',
        'channels',
        'digest_window_minutes',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'digest_window_minutes' => 'integer',
        ];
    }

    public function type(): NotificationType
    {
        return NotificationType::from($this->type);
    }

    /**
     * @return list<NotificationChannel>
     */
    public function selectedChannels(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?NotificationChannel => NotificationChannel::tryFrom($value),
            $this->channels,
        )));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
