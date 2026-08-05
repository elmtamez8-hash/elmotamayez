<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Shared\Data\DataTransferObject;

/**
 * What the provider says about an asset right now.
 *
 * A provider that is down must report Failed rather than throw: an outage should
 * degrade uploading, not break every screen that lists a lesson.
 */
final class AssetStatusReport extends DataTransferObject
{
    /** @param list<Rendition> $renditions */
    public function __construct(
        public readonly MediaAssetStatus $status,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $sizeBytes = null,
        public readonly array $renditions = [],
        public readonly ?string $failureReason = null,
    ) {}

    public static function failed(string $reason): self
    {
        return new self(status: MediaAssetStatus::Failed, failureReason: $reason);
    }
}
