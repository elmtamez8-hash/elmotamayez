<?php

declare(strict_types=1);

namespace App\Modules\Media;

use App\Modules\Media\Contracts\VideoProviderInterface;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Policies\MediaAssetPolicy;
use App\Modules\Media\Providers\LocalVideoProvider;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Gate;

class MediaServiceProvider extends Module
{
    protected string $name = 'Media';

    public function register(): void
    {
        parent::register();

        /*
         * The inversion point. Adding a commercial provider is a file in
         * Providers/ and a case here — nothing in Actions/, Models/ or the
         * frontend changes. Same shape as PaymentProviderInterface, which has
         * shipped with a single implementation since launch.
         */
        $this->app->bind(VideoProviderInterface::class, fn (): VideoProviderInterface => match ((string) config('media.provider')) {
            default => $this->app->make(LocalVideoProvider::class),
        });
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);
    }
}
