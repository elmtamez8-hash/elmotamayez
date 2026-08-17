<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Events\MediaAssetReady;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Shared\Actions\Action;

/**
 * Asks the provider what actually arrived, and settles the asset on that answer.
 *
 * The mime type is read from the file's bytes, never from what the client
 * declared: an .mp4 extension on a zip is trivially easy and would otherwise put
 * an unplayable file behind a lesson.
 *
 * An asset that fails here stays failed and stays visible. A lesson whose upload
 * broke must not be left claiming it has a video (FR-007).
 */
class CompleteMediaUpload extends Action
{
    public function __construct(
        // Resolved from the asset, not from the config: this Action settles assets
        // created long before the current provider was chosen, and asking the
        // configured provider about someone else's file gets "does not exist".
        private readonly MediaProviderResolver $providers,
    ) {}

    public function handle(MediaAsset $asset): MediaAsset
    {
        $provider = $this->providers->for($asset);

        $report = $provider->status($asset);

        $status = $report->status;
        $failureReason = $report->failureReason;

        if ($status === MediaAssetStatus::Ready) {
            $rejection = $this->rejectionReason($asset->kind, $report->mimeType, $report->sizeBytes, $report->durationSeconds);

            if ($rejection !== null) {
                $status = MediaAssetStatus::Failed;
                $failureReason = $rejection;
                // Nothing usable arrived; do not leave the bytes on disk.
                $provider->delete($asset);
            }
        }

        $asset->forceFill([
            'status' => $status,
            'mime_type' => $report->mimeType ?? $asset->mime_type,
            'size_bytes' => $report->sizeBytes ?? $asset->size_bytes,
            'duration_seconds' => $report->durationSeconds ?? $asset->duration_seconds,
            'renditions' => $report->renditions === [] ? null : array_map(
                fn ($rendition): array => $rendition->toArray(),
                $report->renditions,
            ),
            'failure_reason' => $failureReason,
            'ready_at' => $status === MediaAssetStatus::Ready ? now() : null,
        ])->save();

        if ($status === MediaAssetStatus::Ready) {
            // The next phase listens for this to publish a recorded live session
            // as a lesson.
            event(new MediaAssetReady($asset));
        }

        return $asset;
    }

    /**
     * The check that counts.
     *
     * The ticket-time check reads what the client declared, which is a number
     * the client chooses. This one reads the file that arrived — its bytes for
     * the type, its actual length on disk for the size.
     *
     * Per kind since 016. It matched the video list and the 2 GiB video ceiling
     * whatever had been uploaded, so a 60 MB "PDF" passed the only real check
     * and its rejection message, when one came, said "not a supported video".
     */
    private function rejectionReason(
        MediaKind $kind,
        ?string $mimeType,
        ?int $sizeBytes,
        ?int $durationSeconds,
    ): ?string {
        // Fails closed. An undetectable type is not a permission to publish it —
        // that is precisely the shape of a file pretending to be one.
        if ($mimeType === null || ! in_array($mimeType, MediaLimits::allowedMimeTypes($kind), true)) {
            return $kind->rejectionMessage();
        }

        if ($sizeBytes !== null && $sizeBytes > MediaLimits::maxSizeBytes($kind)) {
            return MediaLimits::sizeRefusal($kind);
        }

        $maxDuration = MediaLimits::maxDurationSeconds($kind);

        if ($durationSeconds !== null && $maxDuration !== null && $durationSeconds > $maxDuration) {
            return MediaLimits::durationRefusal($kind);
        }

        return null;
    }
}
