<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Models\PlaybackGrant;

/**
 * The five conditions that let bytes leave the server.
 *
 * Re-evaluated on EVERY range request, not once when playback starts. That is
 * what makes a video stop mid-file when the viewer's session is ended from
 * another device, and what makes hiding the watermark end playback: the
 * watermark drives renewal, renewal stops, the grant expires, and the next range
 * is refused.
 */
final class PlaybackGuard
{
    /**
     * @return PlaybackGrant|null the grant if playback may continue, null otherwise
     */
    public static function resolve(string $token): ?PlaybackGrant
    {
        $grant = PlaybackGrant::query()
            ->with(['asset', 'session'])
            ->where('uuid', $token)
            ->first();

        if ($grant === null) {
            return null;
        }

        if ($grant->revoked_at !== null || $grant->expires_at->isPast()) {
            return null;
        }

        if ($grant->session->status !== AuthSession::STATUS_ACTIVE) {
            return null;
        }

        // An asset can move to failed after a grant was issued — a provider
        // reporting a deleted file, for instance.
        if (! $grant->asset->isPlayable()) {
            return null;
        }

        return $grant;
    }
}
