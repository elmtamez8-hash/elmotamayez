<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

class DeleteMediaAsset extends Action
{
    public function __construct(
        // From the asset's own column. Deleting through the configured provider is
        // how a file survives the row that pointed at it: the wrong provider
        // reports success at having nothing to remove.
        private readonly MediaProviderResolver $providers,
    ) {}

    public function handle(MediaAsset $asset): void
    {
        // Before the row is gone: the resolver reads the asset, and the delete
        // below needs its provider id.
        $provider = $this->providers->for($asset);

        DB::transaction(function () use ($asset): void {
            // Kill live grants first. Deleting the asset alone would leave a
            // viewer mid-stream on a file that no longer exists.
            $asset->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $asset->captions()->delete();
            $asset->delete();
        });

        $provider->delete($asset);
    }
}
