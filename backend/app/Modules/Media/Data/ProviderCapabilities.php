<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What this provider can actually do.
 *
 * This class is the answer to the awkward question in this phase: how do you
 * honour a requirement that needs a provider you have not chosen yet? By
 * declaring the capability instead of assuming it. The UI hides the quality
 * control when adaptiveBitrate is false, and the contract test forces any
 * implementation that claims a capability to deliver it.
 */
final class ProviderCapabilities extends DataTransferObject
{
    public function __construct(
        public readonly bool $adaptiveBitrate,
        public readonly bool $signedUrls,
        public readonly bool $automaticCaptions,
        public readonly bool $directUpload,
        public readonly int $maxSizeBytes,
        public readonly int $maxDurationSeconds,
    ) {}
}
