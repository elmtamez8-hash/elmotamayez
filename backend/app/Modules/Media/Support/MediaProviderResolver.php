<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Enums\MediaKind;
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
    /**
     * @param  array<string, class-string<MediaProviderInterface>>  $map
     * @param  string  $fallback  The map key for the provider that takes anything —
     *                            our own disk. Injected rather than assumed, so this
     *                            file still names nobody.
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $map,
        private readonly string $fallback,
    ) {}

    /**
     * Which provider should take a NEW file of this kind.
     *
     * ⚠️ A DIFFERENT QUESTION FROM `for()`, AND THE ONLY ONE ASKABLE BEFORE A ROW
     * EXISTS. `for()` reads a column; this reads a declaration. Both are needed
     * because an upload ticket is issued before there is anything to read.
     *
     * The configured provider gets first refusal and the fallback takes what it
     * declines — so a video host receives video and a worksheet stays on our disk.
     * Until this existed, every kind went to whatever `media.provider` named, and
     * flipping that switch had a video object created for every PDF a teacher
     * uploaded: wrong ceilings, wrong library, and a failure message about the wrong
     * thing. Observed on a real account on 2026-08-17.
     */
    public function forKind(MediaKind $kind): MediaProviderInterface
    {
        $configured = $this->container->make(MediaProviderInterface::class);

        if ($configured->capabilities()->accepts($kind)) {
            return $configured;
        }

        $class = $this->map[$this->fallback] ?? null;

        if ($class === null) {
            throw new RuntimeException(
                "مزوّد الوسائط الاحتياطيّ «{$this->fallback}» غير مُهيَّأ في هذا النظام."
            );
        }

        return $this->container->make($class);
    }

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
