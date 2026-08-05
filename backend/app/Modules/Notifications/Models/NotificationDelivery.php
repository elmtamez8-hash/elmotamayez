<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\BaseModel;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Notifications\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $channel
 * @property string $status
 * @property int $attempts
 * @property-read Notification $notification
 */
class NotificationDelivery extends BaseModel
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        'notification_id',
        'channel',
        'template_id',
        'status',
        'attempts',
        'failure_reason',
        'deferred_until',
        'last_attempted_at',
        'delivered_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'deferred_until' => 'datetime',
            'last_attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::from($this->channel);
    }

    public function status(): DeliveryStatus
    {
        return DeliveryStatus::from($this->status);
    }

    public function markDelivered(): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::Delivered->value,
            'delivered_at' => now(),
            'last_attempted_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::Failed->value,
            'last_attempted_at' => now(),
            // Truncated to the column width: a provider stack trace is longer than
            // the log needs, and MySQL in strict mode rejects the row rather than
            // trimming it, which would lose the failure entirely.
            'failure_reason' => mb_substr($reason, 0, 500),
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::Skipped->value,
            'last_attempted_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 500),
        ])->save();
    }

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    /** @return BelongsTo<MessageTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }
}
