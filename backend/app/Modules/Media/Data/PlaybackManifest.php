<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Modules\Media\Enums\PlaybackFormat;
use App\Shared\Data\DataTransferObject;
use Carbon\CarbonImmutable;

/**
 * What to play, valid for as long as the grant is.
 *
 * `isRedirect` is what keeps the provider out of our payloads: the client always
 * sees our own route, and a commercial provider turns that route into a 302 to
 * its signed URL. The browser then pulls segments straight from the CDN — no
 * video passes through PHP, and no library id appears in any JSON we emit.
 */
final class PlaybackManifest extends DataTransferObject
{
    /** @param list<Rendition> $renditions */
    public function __construct(
        public readonly PlaybackFormat $format,
        public readonly string $url,
        public readonly bool $isRedirect,
        public readonly CarbonImmutable $expiresAt,
        public readonly array $renditions = [],
    ) {}
}
