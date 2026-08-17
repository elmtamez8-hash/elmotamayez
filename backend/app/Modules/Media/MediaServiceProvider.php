<?php

declare(strict_types=1);

namespace App\Modules\Media;

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Policies\MediaAssetPolicy;
use App\Modules\Media\Providers\BunnyMediaProvider;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class MediaServiceProvider extends Module
{
    protected string $name = 'Media';

    public function register(): void
    {
        parent::register();

        /*
         * The inversion point. Adding a commercial provider is a file in
         * Providers/ and a line in the map below — nothing in Actions/, Models/ or
         * the frontend changes. Same shape as PaymentProviderInterface, which has
         * shipped with a single implementation since launch.
         *
         * The provider for an asset that does not exist yet: an upload ticket is
         * asked for before there is a row to read a column from, so this is the
         * only correct answer there. For an asset that DOES exist, the resolver
         * below reads that asset's own column instead.
         */
        $this->app->bind(MediaProviderInterface::class, fn (): MediaProviderInterface => $this->app->make(
            $this->providerClass((string) config('media.provider')),
        ));

        /*
         * Given the map rather than holding it, so this file stays the only place
         * in `app/` that names a provider — the rule ProviderNameContainmentTest
         * enforces. A resolver with its own copy of the list would also be a second
         * place to update, which is how a provider becomes resolvable in one
         * direction only.
         */
        $this->app->singleton(MediaProviderResolver::class, fn (): MediaProviderResolver => new MediaProviderResolver(
            $this->app,
            self::PROVIDERS,
            // The provider that takes any kind: our own disk. Named here with the
            // rest of them, so a kind the configured provider declines has somewhere
            // to go without the resolver knowing whose disk it is.
            fallback: self::FALLBACK_PROVIDER,
        ));
    }

    /**
     * Every provider this deployment can speak to.
     *
     * @var array<string, class-string<MediaProviderInterface>>
     */
    private const PROVIDERS = [
        'local' => LocalMediaProvider::class,
        'bunny' => BunnyMediaProvider::class,
    ];

    /**
     * Where a kind the configured provider declines goes instead.
     *
     * ⚠️ NOT A SILENT FALL BACK FOR AN UNKNOWN NAME — that still throws, see below.
     * This is the answer to "who stores a worksheet when the video host will not",
     * and it is our own disk because that is the one place with no kind it refuses.
     */
    private const FALLBACK_PROVIDER = 'local';

    /**
     * @return class-string<MediaProviderInterface>
     *
     * @throws RuntimeException on an unknown name — never a silent fall back to
     *                          local. A typo in MEDIA_PROVIDER would otherwise
     *                          send every new recording to our own disk in
     *                          production, which is the exact failure this phase
     *                          exists to remove, arriving quietly.
     */
    private function providerClass(string $name): string
    {
        return self::PROVIDERS[$name]
            ?? throw new RuntimeException("مزوّد الوسائط «{$name}» غير معروف.");
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);
    }
}
