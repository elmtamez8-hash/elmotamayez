<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Support\NotificationChannel;
use RuntimeException;

/**
 * A channel that exists only in tests, registered the same way a real one would
 * be — one class, one tag line.
 *
 * It is the proof for SC-001: if this receives every notification type without a
 * single listener or action being touched, then WhatsApp will too. Never a
 * network call (NFR-010).
 */
class FakeChannel implements NotificationChannelInterface
{
    /** @var list<NotificationEnvelope> */
    public array $sent = [];

    public bool $enabled = true;

    public bool $reachable = true;

    /** Next send() throws this instead of recording. */
    public ?string $failWith = null;

    public bool $failPermanently = false;

    public function __construct(
        private readonly NotificationChannel $channel = NotificationChannel::Email,
    ) {}

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function canReach(NotificationEnvelope $envelope): bool
    {
        return $this->reachable;
    }

    public function send(NotificationEnvelope $envelope): void
    {
        if ($this->failWith !== null) {
            throw $this->failPermanently
                ? PermanentDeliveryException::invalidRecipient($this->failWith)
                : new RuntimeException($this->failWith);
        }

        $this->sent[] = $envelope;
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
