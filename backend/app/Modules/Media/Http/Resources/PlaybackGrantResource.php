<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Contracts\VideoProviderInterface;
use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Models\PlaybackGrant;
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
        $provider = app(VideoProviderInterface::class);

        $manifest = $provider->manifest(new PlaybackContext(
            asset: $this->asset,
            grant: $this->resource,
        ));

        return [
            'grant' => $this->uuid,
            'expires_at' => $this->expires_at->toIso8601String(),
            'manifest_url' => $manifest->url,
            'format' => $manifest->format->value,
            // Built on the server, masked on the server: sending the full number
            // and hiding it in CSS would put it one network-tab click away.
            'watermark' => WatermarkPayload::for($this->user),
            'renew_after_seconds' => (int) config('media.grant_renew_interval_seconds'),
            'duration_seconds' => $this->asset->duration_seconds,
            'renditions' => $manifest->renditions,
            'captions' => $this->asset->captions->map(fn ($caption): array => [
                'uuid' => $caption->uuid,
                'language' => $caption->language,
                'kind' => $caption->kind->value,
                'is_default' => $caption->is_default,
                // Through the grant, like the video: the track expires with it
                // rather than being a permanent link to the lesson's script.
                'url' => url("/api/v1/playback/{$this->uuid}/captions/{$caption->uuid}"),
            ])->all(),
        ];
    }
}
