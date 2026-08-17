<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Data\AssetStatusReport;
use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Data\PlaybackManifest;
use App\Modules\Media\Data\ProviderCapabilities;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Models\MediaAsset;

/**
 * Abstraction over a media provider: our own private disk, or a commercial
 * streaming network.
 *
 * ⚠️ NO VENDOR IS NAMED HERE, and the omission is enforced. This docblock used to
 * list three of them as examples, which read as harmless until 019 made one of them
 * real: a contract that names its implementations is a contract a reader consults to
 * find out which provider is in use, and `ProviderNameContainmentTest` now fails the
 * build over it. The names live in the adapter and at the binding.
 *
 * Named for video until 016, which is when `pdf`, `audio` and `file` gained an
 * upload path. Nothing in the interface was video-specific — a ticket, a status
 * report, a manifest and a delete describe any stored file — so the rename is
 * the whole change. Leaving it called Video would have meant every document
 * upload travelling through a contract whose name says it does not handle them,
 * which is how the wrong mime list survives a review.
 *
 * Lesson and session logic depends on this interface and never names a provider.
 * Adding one is a file in Providers/ plus a case in MediaServiceProvider — the
 * same shape as PaymentProviderInterface, which has shipped with a single
 * implementation since launch.
 *
 * The commercial choice is deliberately deferred: opening an account and
 * comparing prices is a business decision, and it must not hold up the code.
 * What that costs is stated rather than hidden — see the deferred-verification
 * table in the feature plan.
 */
interface MediaProviderInterface
{
    /**
     * The short name stored on every asset. Never exposed in a payload.
     *
     * This is what `MediaProviderResolver` reads to hand an existing asset back to
     * the provider that actually holds it — so it is an identifier with consequences,
     * not a label. Changing one orphans every row carrying the old value.
     */
    public function identifier(): string;

    /**
     * What this provider can actually do.
     *
     * Declared, not assumed. The contract test forces any implementation that
     * claims a capability to deliver it, which is what makes deferring the
     * provider choice safe rather than optimistic.
     */
    public function capabilities(): ProviderCapabilities;

    /** Where the client uploads to, and how. Must never carry a provider key. */
    public function createUploadTicket(MediaAsset $asset): UploadTicket;

    /**
     * Take this file over from a URL we already hold.
     *
     * The first change to this interface since it was written, and 019 is why:
     * `createUploadTicket` answers "where should a BROWSER send bytes", which is
     * the wrong question for a recording that already exists in our own bucket.
     * Without this method the only way to hand that file over is to download it
     * into a worker and upload it again — a gigabyte per lesson through the
     * application server, which is the entire cost this phase exists to remove.
     * A provider that can fetch for itself is given the chance to.
     *
     * ⚠️ ASYNCHRONOUS BY NATURE, so it returns nothing. The provider pulls and
     * transcodes on its own clock; the asset is left `Processing` and `status()`
     * is what later says it is `Ready`. A return value here would be a promise
     * about a job that has not started.
     *
     * @param  string  $sourceUrl  Short-lived and signed. Never a public URL: the
     *                             point is that no lasting link to the file exists.
     * @param  array<string, string>  $sourceHeaders  Sent by the provider with its
     *                                                own GET, for a source that
     *                                                authenticates by header rather
     *                                                than by signed URL.
     */
    public function ingestFromUrl(MediaAsset $asset, string $sourceUrl, array $sourceHeaders = []): void;

    /**
     * The provider's current view of the asset.
     *
     * Must report a failed status rather than throw when the provider is
     * unreachable: an outage should stop uploads, not break every lesson screen.
     */
    public function status(MediaAsset $asset): AssetStatusReport;

    /**
     * A manifest valid for this viewer, this session, and this grant's lifetime.
     *
     * Called on every range request, so it must be cheap and must not assume it
     * is the first call for a grant.
     */
    public function manifest(PlaybackContext $context): PlaybackManifest;

    /** Remove the asset at the provider. Idempotent: deleting twice is not an error. */
    public function delete(MediaAsset $asset): void;
}
