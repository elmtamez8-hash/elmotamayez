<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Media\Support\WatermarkPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the player needs, and nothing about where the video lives.
 *
 * No `provider`, no `provider_asset_id`, no storage path, no permanent URL. The
 * manifest_url is always our own route; a commercial provider turns that route
 * into a redirect rather than appearing here (FR-011).
 *
 * @mixin PlaybackGrant
 */
class PlaybackGrantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $manifest = app(MediaProviderResolver::class)->for($this->asset)->manifest(new PlaybackContext(
            asset: $this->asset,
            grant: $this->resource,
        ));

        return [
            'grant' => $this->uuid,
            'expires_at' => $this->expires_at->toIso8601String(),
            /*
             * ⚠️ OUR ROUTE, NEVER `$manifest->url` — AND THAT IS NOT A STYLE
             * CHOICE. For a redirect provider that URL is the CDN hostname plus
             * the provider's own asset id, which is the one field FR-011 and this
             * class's docblock forbid in a payload. It reached here for free while
             * the only provider pointed the manifest back at us, and would have
             * started leaking the moment one did not.
             *
             * The signed URL exists only as the Location of the 302 from
             * `stream()`; a segmented player follows that redirect and resolves
             * its segments against the final URL. It also matches what `renew()`
             * already returns — two answers to "where do I play from" is how a
             * renewal silently moves a viewer.
             *
             * ⚠️ AND «EVERY PLAYLIST FETCH IS A FRESH TRIP THROUGH THE GRANT» IS
             * FALSE FOR A REDIRECT PROVIDER — this comment used to say it. A VOD
             * playlist is fetched ONCE and never refreshed, so after that single
             * redirect the segments come from the provider's own host and this
             * server is not consulted again for the rest of the lesson. That is
             * why `reload_after_seconds` exists: the player is told to come BACK
             * through here, because nothing else would bring it. Removing the
             * watermark stops the renewal, the reload then fails, and the signed
             * token expires — which is the enforcement. It is not instant the way
             * a range request is against our own disk, and pretending otherwise
             * would hide the one place a viewer keeps watching after entitlement
             * ends.
             */
            /*
             * ⚠️ RELATIVE, NOT `url()`. An absolute address built from `APP_URL`
             * points at the API's own origin, which is not the page's: in
             * development that is :8000 against a page on :3000, and in production
             * it is whatever the API is deployed under rather than the host the
             * student is looking at. A relative path resolves against the page and
             * travels through the same rewrite every other call uses.
             *
             * It matters most for the CAPTION below, which a `<track>` fetches
             * under the cross-origin text-track rules: a mismatched origin makes it
             * a silent network error, no caption and no message — and the
             * transcript panel beside it keeps working, because it uses `fetch()`,
             * so the feature looks alive while the subtitles never load.
             */
            'manifest_url' => "/api/v1/playback/{$this->uuid}/stream",
            'format' => $manifest->format->value,
            // Built on the server, masked on the server: sending the full number
            // and hiding it in CSS would put it one network-tab click away.
            'watermark' => WatermarkPayload::for($this->user),
            'renew_after_seconds' => (int) config('media.grant_renew_interval_seconds'),
            /*
             * ⚠️ HOW OFTEN THE PLAYER MUST COME BACK THROUGH `stream()`, AND NULL
             * WHEN IT NEVER HAS TO.
             *
             * A provider that serves bytes itself is re-authorised on every range
             * request, so the grant expiring cuts playback where it stands. A
             * provider that answers a REDIRECT is not: the browser fetches the
             * manifest once, then talks to the CDN directly with a URL signed for
             * the grant's expiry AS IT STOOD at that moment. Renewal extends the
             * row, not the URL the player is already holding — so without a reload
             * the lesson dies at the first token expiry, and no amount of renewing
             * reaches it.
             *
             * Which makes the grant TTL ONE number with TWO consequences now: it is
             * the revocation latency (how long a revoked viewer keeps watching) and
             * the reload cadence (how often the player re-buffers). Shortening it
             * tightens the first and worsens the second. The alternative — a long
             * token — was rejected deliberately: it would make PlaybackGuard a
             * once-per-viewing check, and every claim about ending a session from
             * another device, the device limit, and the watermark being the renewal
             * loop would quietly stop being true.
             *
             * Two thirds of the TTL, so a reload that fails still has a live token
             * to retry under. `isRedirect` rather than the format, because the
             * question is who serves the bytes, not how they are segmented.
             */
            'reload_after_seconds' => $manifest->isRedirect
                ? max(30, intdiv((int) config('media.grant_ttl_seconds') * 2, 3))
                : null,
            'duration_seconds' => $this->asset->duration_seconds,
            'renditions' => $manifest->renditions,
            'captions' => $this->asset->captions->map(fn ($caption): array => [
                'uuid' => $caption->uuid,
                'language' => $caption->language,
                'kind' => $caption->kind->value,
                'is_default' => $caption->is_default,
                // Through the grant, like the video: the track expires with it
                // rather than being a permanent link to the lesson's script.
                'url' => "/api/v1/playback/{$this->uuid}/captions/{$caption->uuid}",
            ])->all(),
        ];
    }
}
