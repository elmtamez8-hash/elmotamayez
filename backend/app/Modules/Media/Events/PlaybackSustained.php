<?php

declare(strict_types=1);

namespace App\Modules\Media\Events;

use App\Modules\Media\Actions\RenewPlaybackGrant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One viewing of one asset has run long enough to count as «watched».
 *
 * Fired by {@see RenewPlaybackGrant} at most ONCE per
 * grant — on the renewal whose server-clock age first crosses the threshold —
 * so a viewer with the page open for two hours does not send one per minute.
 *
 * Media does not know what the asset belongs to beyond its owner columns, and
 * says nothing about sessions or attendance: whoever cares about a kind of owner
 * claims it (a class-session recording is LiveSessions' business). Identifiers
 * only, because the listener is queued and a serialised model would be a stale
 * copy of a row it re-reads anyway.
 */
class PlaybackSustained
{
    use Dispatchable;

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly int $userId,
    ) {}
}
