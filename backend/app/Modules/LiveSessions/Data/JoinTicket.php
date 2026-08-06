<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Data;

use App\Shared\Data\DataTransferObject;
use Carbon\CarbonInterface;

/**
 * The only thing that crosses into the browser.
 *
 * It carries no provider key and no secret (FR-019), and it is short-lived: a
 * leaked ticket expires on its own, and no ticket opens a room that has been
 * closed.
 */
final class JoinTicket extends DataTransferObject
{
    public function __construct(
        public readonly string $roomUrl,
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
        public readonly string $role,
    ) {}
}
