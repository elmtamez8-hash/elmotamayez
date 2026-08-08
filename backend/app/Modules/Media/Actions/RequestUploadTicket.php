<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Reserves an asset row and returns somewhere to upload to.
 *
 * The declared size and duration are checked before a single byte moves. It is a
 * claim the client can lie about, and the real check happens on completion
 * against the file itself — but rejecting an oversized upload up front saves the
 * teacher from watching a gigabyte transfer that was always going to fail.
 */
class RequestUploadTicket extends Action
{
    public function __construct(
        private readonly MediaProviderInterface $provider,
    ) {}

    /** @return array{asset: MediaAsset, ticket: UploadTicket} */
    public function handle(
        Lesson $lesson,
        string $originalFilename,
        ?int $declaredSizeBytes = null,
        ?int $declaredDurationSeconds = null,
    ): array {
        $this->assertWithinLimits($declaredSizeBytes, $declaredDurationSeconds);

        // One asset per lesson. Re-uploading replaces what is there rather than
        // leaving an orphan the teacher cannot see or delete.
        $existing = $lesson->mediaAsset;

        if ($existing !== null) {
            $this->provider->delete($existing);
            $existing->delete();
        }

        $asset = new MediaAsset([
            'workspace_id' => $lesson->workspace_id,
            'owner_type' => Lesson::class,
            'owner_id' => $lesson->getKey(),
            'provider' => $this->provider->identifier(),
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $originalFilename,
            'size_bytes' => $declaredSizeBytes,
            'duration_seconds' => $declaredDurationSeconds,
        ]);
        $asset->save();

        return [
            'asset' => $asset,
            'ticket' => $this->provider->createUploadTicket($asset),
        ];
    }

    private function assertWithinLimits(?int $sizeBytes, ?int $durationSeconds): void
    {
        $maxSize = (int) PlatformSettings::get('media.max_size_bytes');
        $maxDuration = (int) PlatformSettings::get('media.max_duration_seconds');

        if ($sizeBytes !== null && $sizeBytes > $maxSize) {
            throw new DomainException('حجم الملف يتجاوز الحد المسموح.');
        }

        if ($durationSeconds !== null && $durationSeconds > $maxDuration) {
            throw new DomainException('مدة الفيديو تتجاوز الحد المسموح.');
        }
    }
}
