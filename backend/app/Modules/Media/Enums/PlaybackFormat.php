<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * How the player should consume what the manifest points at.
 *
 * The player reads this rather than guessing from the URL, and refuses a format
 * the browser cannot play with a clear message instead of a black screen.
 */
enum PlaybackFormat: string
{
    /** A single file served with byte ranges. What the local provider produces. */
    case Progressive = 'progressive';

    /** A segmented manifest. Needs a provider that produces one. */
    case Hls = 'hls';
}
