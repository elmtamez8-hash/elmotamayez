<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A READY document asset made from a file the SERVER produced — the pages of an
 * imported PDF (spec 039 · story 4). No upload ticket: the bytes are already
 * here, so they go onto our disk and the row says Ready only after they do.
 *
 * ⚠️ LOCAL PROVIDER ONLY, as `CopyLocalMediaAsset`: documents are kept on our
 * disk in production (the video provider takes video only); a remote document provider is
 * refused rather than half-written.
 */
final class StoreLocalMediaFile extends Action
{
    public function __construct(private readonly MediaProviderResolver $providers) {}

    public function handle(string $localPath, string $filename, string $mimeType, Model $owner, int $workspaceId, ?int $uploaderId): MediaAsset
    {
        $provider = $this->providers->forKind(MediaKind::Document);
        if (! $provider instanceof LocalMediaProvider) {
            throw new LogicException('Only the local provider can store a server-made file.');
        }

        $asset = new MediaAsset([
            'workspace_id' => $workspaceId,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'uploaded_by_user_id' => $uploaderId,
            'provider' => $provider->identifier(),
            'kind' => MediaKind::Document,
            'role' => MediaRole::Attachment,
            'is_downloadable' => false,
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => (int) filesize($localPath),
        ]);
        $asset->save();

        $path = $provider->pathFor($asset);
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new LogicException("Cannot read [{$localPath}].");
        }
        try {
            $provider->disk()->put($path, $stream);
        } finally {
            fclose($stream);
        }

        $asset->forceFill(['provider_asset_id' => $path, 'status' => MediaAssetStatus::Ready, 'ready_at' => now()])->save();

        return $asset;
    }
}
