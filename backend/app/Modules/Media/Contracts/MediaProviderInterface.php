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
 * Abstraction over a media provider (local disk, Bunny Stream, Cloudflare, Mux, ...).
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
    /** Identifier: 'local', 'bunny', 'cloudflare', ... Never exposed in a payload. */
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
