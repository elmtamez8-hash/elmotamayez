<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Shared\Data\DataTransferObject;

/** Everything a provider needs to mint a manifest for one viewer, once. */
final class PlaybackContext extends DataTransferObject
{
    public function __construct(
        public readonly MediaAsset $asset,
        public readonly PlaybackGrant $grant,
        public readonly ?string $viewerIpHash = null,
    ) {}
}
