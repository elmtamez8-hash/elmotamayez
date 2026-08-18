<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Data\AssetStatusReport;
use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Data\PlaybackManifest;
use App\Modules\Media\Data\ProviderCapabilities;
use App\Modules\Media\Data\Rendition;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\PlaybackFormat;
use App\Modules\Media\Models\MediaAsset;
use Carbon\CarbonImmutable;

/**
 * A provider that claims adaptive bitrate and actually delivers it.
 *
 * Exists so the contract test proves something. Without an implementation that
 * declares the capability, the rule "whoever claims it must deliver it" would
 * never execute, and the first commercial provider to claim it falsely would
 * find out in production instead of in CI.
 *
 * `$breakPromise` flips it into an implementation that lies, so the test can
 * confirm the check fails when it should.
 */
class FakeMediaProvider implements MediaProviderInterface
{
    public function __construct(
        public bool $breakPromise = false,
        public bool $unreachable = false,
        /*
         * A provider that has taken the file and is still encoding it.
         *
         * The one answer no other knob here could give, and the one the commercial
         * provider gives for minutes on end: `Ready` and `failed` are both verdicts,
         * and the phase between them is where the ingest job's attempt budget used
         * to be spent by mistake.
         */
        public bool $stillProcessing = false,
    ) {}

    public function identifier(): string
    {
        return 'fake';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            adaptiveBitrate: true,
            signedUrls: true,
            automaticCaptions: false,
            directUpload: true,
            maxSizeBytes: 1_073_741_824,
            maxDurationSeconds: 7200,
            remoteFetch: true,
        );
    }

    public function ingestFromUrl(MediaAsset $asset, string $sourceUrl, array $sourceHeaders = []): void
    {
        // A provider that fetches for itself and tells us nothing yet — which is
        // the shape the contract describes, and the reason the return is void.
        $asset->forceFill(['provider_asset_id' => 'fake-'.$asset->uuid])->save();
    }

    public function createUploadTicket(MediaAsset $asset): UploadTicket
    {
        return new UploadTicket(
            url: 'https://fake.test/upload/'.$asset->uuid,
            method: 'PUT',
            headers: [],
            fields: [],
            expiresAt: CarbonImmutable::now()->addHour(),
        );
    }

    public function status(MediaAsset $asset): AssetStatusReport
    {
        if ($this->unreachable) {
            return AssetStatusReport::failed('تعذّر الوصول إلى مزوّد الفيديو.');
        }

        if ($this->stillProcessing) {
            return new AssetStatusReport(status: MediaAssetStatus::Processing);
        }

        return new AssetStatusReport(
            status: MediaAssetStatus::Ready,
            durationSeconds: 600,
            mimeType: 'video/mp4',
            sizeBytes: 4096,
            renditions: $this->renditions(),
        );
    }

    public function manifest(PlaybackContext $context): PlaybackManifest
    {
        return new PlaybackManifest(
            format: PlaybackFormat::Hls,
            url: url("/api/v1/playback/{$context->grant->uuid}/stream"),
            isRedirect: true,
            expiresAt: CarbonImmutable::instance($context->grant->expires_at->toDateTimeImmutable()),
            renditions: $this->renditions(),
        );
    }

    public function delete(MediaAsset $asset): void
    {
        // Nothing to remove, and deleting twice must not be an error.
    }

    /** @return list<Rendition> */
    private function renditions(): array
    {
        if ($this->breakPromise) {
            return [];
        }

        return [
            new Rendition('360p', 360, 800),
            new Rendition('720p', 720, 2500),
        ];
    }
}
