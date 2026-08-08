<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Events\MediaAssetReady;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\PlatformSettings;
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
        private readonly MediaProviderInterface $provider,
    ) {}

    public function handle(MediaAsset $asset): MediaAsset
    {
        $report = $this->provider->status($asset);

        $status = $report->status;
        $failureReason = $report->failureReason;

        if ($status === MediaAssetStatus::Ready) {
            $rejection = $this->rejectionReason($report->mimeType, $report->sizeBytes, $report->durationSeconds);

            if ($rejection !== null) {
                $status = MediaAssetStatus::Failed;
                $failureReason = $rejection;
                // Nothing usable arrived; do not leave the bytes on disk.
                $this->provider->delete($asset);
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

    private function rejectionReason(?string $mimeType, ?int $sizeBytes, ?int $durationSeconds): ?string
    {
        // Video for now; 016 replaces the literal with the asset's own kind once
        // media_assets carries one. The list moved under a per-kind key first so
        // documents and audio have somewhere to be declared.
        /** @var list<string> $allowed */
        $allowed = config('media.allowed_mime_types.video', []);

        // Fails closed. An undetectable type is not a permission to publish it as
        // a video — that is precisely the shape of a file pretending to be one.
        if ($mimeType === null || ! in_array($mimeType, $allowed, true)) {
            return 'الملف المرفوع ليس ملف فيديو مدعوماً.';
        }

        if ($sizeBytes !== null && $sizeBytes > (int) PlatformSettings::get('media.max_size_bytes')) {
            return 'حجم الملف يتجاوز الحد المسموح.';
        }

        if ($durationSeconds !== null && $durationSeconds > (int) PlatformSettings::get('media.max_duration_seconds')) {
            return 'مدة الفيديو تتجاوز الحد المسموح.';
        }

        return null;
    }
}
