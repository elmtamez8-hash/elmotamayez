<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Models\User;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Data\DataTransferObject;

/**
 * Everything a channel needs to deliver one message, and nothing else.
 *
 * Deliberately carries no Notification or NotificationDelivery model. A channel
 * that holds the delivery record will eventually write to it, and then two places
 * decide what "delivered" means.
 */
final class NotificationEnvelope extends DataTransferObject
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly User $recipient,
        public readonly NotificationType $type,
        public readonly string $titleAr,
        public readonly string $bodyAr,
        public readonly ?string $actionUrl,
        public readonly array $payload,
        /** Lets a channel reference the record without being able to change it. */
        public readonly string $notificationUuid,
    ) {}
}
