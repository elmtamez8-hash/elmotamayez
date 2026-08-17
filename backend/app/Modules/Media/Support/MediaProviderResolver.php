<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Which provider owns THIS asset — read from the asset, not from the config.
 *
 * `media_assets.provider` has been written on every row since 004 and read by
 * nothing. That was harmless while there was one provider and became a data-loss
 * bug the moment there were two: the container binds one provider from
 * `media.provider`, so the day production flips to a commercial provider, every
 * lesson recorded before that day is handed to it — and answered with "file not
 * found", because those bytes are on our own disk and always will be.
 *
 * ⚠️ THIS SITS BESIDE THE BINDING AND DOES NOT REPLACE IT. `createUploadTicket`
 * is asked for an asset that does not exist yet, so there is no column to read;
 * that caller keeps getting the configured provider. Replacing the binding
 * outright would leave the inversion point with no answer at all.
 *
 * ⚠️ AND THE NAMES LIVE IN MediaServiceProvider, NOT HERE. The map is injected
 * for two reasons: one file names the providers, which is the rule
 * `ProviderNameContainmentTest` enforces — and a resolver that hard-codes the
 * list would be a second place to update, which is how a provider becomes
 * resolvable in one direction only.
 *
 * The trap this is written against is subtle: a resolver that exists while some
 * callers still use the binding is GREEN in every test, because a test's config
 * matches its fixture's column. That is why the guard is two assets for two
 * different providers in one database (SC-011) — one asset cannot see the bug.
 */
final class MediaProviderResolver
{
    /** @param array<string, class-string<MediaProviderInterface>> $map */
    public function __construct(
        private readonly Container $container,
        private readonly array $map,
    ) {}

    /**
     * @throws RuntimeException when the asset names a provider this deployment
     *                          does not have. Loudly, because the alternative is
     *                          quietly handing the file to the wrong provider and
     *                          reporting that it does not exist (FR-003ب).
     */
    public function for(MediaAsset $asset): MediaProviderInterface
    {
        $name = (string) $asset->provider;
        $class = $this->map[$name] ?? null;

        if ($class === null) {
            throw new RuntimeException(
                "مزوّد الوسائط «{$name}» غير مُهيَّأ في هذا النظام (الأصل {$asset->uuid})."
            );
        }

        return $this->container->make($class);
    }
}
