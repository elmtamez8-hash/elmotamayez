<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\BaseModel;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Notifications\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $key
 * @property string $type
 * @property string $channel
 * @property string $title_ar
 * @property string $body_ar
 * @property array<int, string>|null $variables
 * @property string $provider_approval_status
 * @property bool $is_active
 */
class MessageTemplate extends BaseModel
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory, HasUuid;

    public const APPROVAL_NOT_REQUIRED = 'not_required';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'key',
        'type',
        'channel',
        'title_ar',
        'body_ar',
        'variables',
        'provider_approval_status',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function keyFor(NotificationType $type, NotificationChannel $channel): string
    {
        return $type->value.'.'.$channel->value;
    }

    /**
     * Whether the provider will accept this template. `not_required` covers the
     * channels that vet nothing (in-app, and email in practice); the rest must
     * have come back approved (FR-037).
     */
    public function isSendable(): bool
    {
        return $this->is_active
            && in_array($this->provider_approval_status, [self::APPROVAL_NOT_REQUIRED, self::APPROVAL_APPROVED], true);
    }
}
