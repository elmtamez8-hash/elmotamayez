<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Support\NotificationChannel;

/**
 * The launch channel, and the only implemented one (FR-004).
 *
 * send() writes nothing. The notification row was created by DispatchNotification
 * before any channel was consulted, because FR-007 requires exactly one record
 * however many channels carry it — so by the time this runs, the message is
 * already sitting in the recipient's feed. Marking the delivery row delivered is
 * the job's business, not the channel's.
 *
 * It is still a real channel rather than a special case in the dispatcher: it has
 * to be selectable in preferences, and SC-001 is only meaningful if every channel
 * goes through the same door.
 */
class InAppChannel implements NotificationChannelInterface
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::InApp;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function canReach(NotificationEnvelope $envelope): bool
    {
        // Anyone with an account can be reached in their own feed. There is no
        // contact detail to verify — which is exactly why this is the MVP channel.
        return true;
    }

    public function send(NotificationEnvelope $envelope): void
    {
        // Intentionally empty. See the class docblock.
    }
}
