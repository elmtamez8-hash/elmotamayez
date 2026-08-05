<?php

declare(strict_types=1);

namespace App\Modules\Media\Events;

use App\Modules\Media\Models\MediaAsset;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A video finished processing and can be played.
 *
 * Exists for the next phase: a live session ends, its recording lands here, and
 * Learning publishes it as a lesson — without Media knowing that lessons exist.
 */
class MediaAssetReady
{
    use Dispatchable;

    public function __construct(public readonly MediaAsset $asset) {}
}
