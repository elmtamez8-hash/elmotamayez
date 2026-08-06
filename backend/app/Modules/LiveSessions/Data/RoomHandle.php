<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Data;

use App\Shared\Data\DataTransferObject;

/** The provider's reference to a room. Stored on the session, never sent out. */
final class RoomHandle extends DataTransferObject
{
    public function __construct(
        public readonly string $providerRoomId,
        public readonly string $joinBaseUrl,
    ) {}
}
