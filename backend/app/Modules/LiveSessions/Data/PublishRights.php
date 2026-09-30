<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What one person may PUBLISH into the room — in our words, never the vendor's.
 *
 * The camera is not here because it is never taken away: a student arrives with
 * it off (`BroadcastStage` · publish-on-connect) and turning it on is her own
 * choice. What the host decides is the microphone and the shared screen.
 *
 * ⚠️ `$updateOwnSignals` is the raised hand and «لم أفهم» — participant
 * attributes the student writes herself. A host has neither button, so a host
 * ticket carries it false; clearing a student's signals is a server-side admin
 * call and needs nothing from the host's own grant.
 *
 * The adapter translates this into whatever its provider calls a permission,
 * and it is the only file that may — this class names no vendor (017 FR-002).
 */
final class PublishRights extends DataTransferObject
{
    public function __construct(
        public readonly bool $microphone,
        public readonly bool $screenShare,
        public readonly bool $updateOwnSignals = true,
    ) {}

    /** The teacher, or an assistant who passes the host gate. */
    public static function host(): self
    {
        return new self(microphone: true, screenShare: true, updateOwnSignals: false);
    }
}
