<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Models\MediaAsset;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

class DeleteMediaAsset extends Action
{
    public function __construct(
        private readonly MediaProviderInterface $provider,
    ) {}

    public function handle(MediaAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            // Kill live grants first. Deleting the asset alone would leave a
            // viewer mid-stream on a file that no longer exists.
            $asset->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $asset->captions()->delete();
            $asset->delete();
        });

        $this->provider->delete($asset);
    }
}
