<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Exceptions\ChannelNotImplementedException;
use App\Modules\Notifications\Support\NotificationChannel;

/**
 * Every implemented channel, indexed by its enum case.
 *
 * Built from a container tag rather than a config array: a tag is type-checked,
 * so a class that does not implement the interface fails at analysis rather than
 * at 3am, and adding a channel stays one line (SC-001).
 */
final class ChannelRegistry
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    /** @param iterable<NotificationChannelInterface> $channels */
    public function __construct(iterable $channels)
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->channel()->value] = $channel;
        }
    }

    /**
     * Whether a class exists for this channel. This is the single definition of
     * "implemented" — NotificationChannel::isImplemented() asks here.
     */
    public function has(NotificationChannel $channel): bool
    {
        return isset($this->channels[$channel->value]);
    }

    public function get(NotificationChannel $channel): NotificationChannelInterface
    {
        return $this->channels[$channel->value] ?? throw ChannelNotImplementedException::for($channel);
    }

    /**
     * Implemented and switched on. What a user may actually choose (FR-030) and
     * what the dispatcher will actually queue.
     *
     * @return list<NotificationChannel>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->channels as $value => $channel) {
            if ($channel->isEnabled()) {
                $available[] = NotificationChannel::from($value);
            }
        }

        return $available;
    }

    /** @return list<NotificationChannel> */
    public function implemented(): array
    {
        return array_map(
            static fn (string $value): NotificationChannel => NotificationChannel::from($value),
            array_keys($this->channels),
        );
    }
}
