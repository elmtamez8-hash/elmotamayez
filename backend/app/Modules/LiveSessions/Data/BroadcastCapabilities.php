<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What a broadcast provider claims it can do.
 *
 * The contract test holds each implementation to exactly this and no further: a
 * capability declared true must work, one declared false must refuse loudly.
 * That is the mechanism that lets the commercial choice wait without the code
 * pretending it already happened.
 */
final class BroadcastCapabilities extends DataTransferObject
{
    public function __construct(
        public readonly bool $liveMedia,
        public readonly bool $screenShare,
        public readonly bool $recording,
        public readonly bool $hostControls,
        public readonly int $maxParticipants,
    ) {}
}
