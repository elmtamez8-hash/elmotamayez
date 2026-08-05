<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Exceptions;

use App\Modules\Notifications\Support\NotificationChannel;
use RuntimeException;

/**
 * Asked for a channel that is a known value but has no class behind it yet.
 *
 * Reaching this is a bug, not a user error: preferences refuse unimplemented
 * channels on the way in (FR-030) and the dispatcher filters them out again
 * before queueing. It exists so that the bug surfaces loudly instead of as a
 * null somewhere downstream.
 */
final class ChannelNotImplementedException extends RuntimeException
{
    public static function for(NotificationChannel $channel): self
    {
        return new self("القناة {$channel->value} معرَّفة لكنها غير مُنفَّذة.");
    }
}
