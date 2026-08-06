<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Data;

use App\Shared\Data\DataTransferObject;

/** A finished recording, ready to hand to the video pipeline from spec 004. */
final class RecordingArtifact extends DataTransferObject
{
    public function __construct(
        public readonly string $downloadUrl,
        public readonly int $sizeBytes,
        public readonly int $durationSeconds,
        public readonly string $mimeType,
    ) {}
}
