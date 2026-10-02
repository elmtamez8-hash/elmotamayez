<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A second, independent copy of a READY asset for a new owner — the bytes copied
 * on our own disk, so deleting either side never touches the other (spec 039:
 * duplicating a whiteboard).
 *
 * ⚠️ LOCAL PROVIDER ONLY, and it says so. The provider contracts know no «copy»
 * (`ingestFromUrl` is the only inbound door), and a remote provider's asset is
 * refused here rather than half-copied.
 */
final class CopyLocalMediaAsset extends Action
{
    public function __construct(private readonly MediaProviderResolver $providers) {}

    public function handle(MediaAsset $source, Model $owner, int $workspaceId): MediaAsset
    {
        $provider = $this->providers->for($source);

        if (! $provider instanceof LocalMediaProvider) {
            throw new LogicException("Only a local asset can be copied; [{$source->provider}] cannot.");
        }

        if ($source->status !== MediaAssetStatus::Ready || $source->provider_asset_id === null) {
            throw new LogicException('Only a ready asset can be copied.');
        }

        $copy = new MediaAsset([
            'workspace_id' => $workspaceId,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'uploaded_by_user_id' => $source->uploaded_by_user_id,
            'provider' => $source->provider,
            'kind' => $source->kind,
            'role' => $source->role,
            'is_downloadable' => $source->is_downloadable,
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $source->original_filename,
            'mime_type' => $source->mime_type,
            'size_bytes' => $source->size_bytes,
            'duration_seconds' => $source->duration_seconds,
        ]);
        $copy->save();

        $path = $provider->pathFor($copy);
        $provider->disk()->copy($source->provider_asset_id, $path);

        // Ready only once the bytes are there: a row that says Ready with no file
        // behind it serves nothing.
        $copy->forceFill(['provider_asset_id' => $path, 'status' => MediaAssetStatus::Ready, 'ready_at' => now()])->save();

        return $copy;
    }
}
