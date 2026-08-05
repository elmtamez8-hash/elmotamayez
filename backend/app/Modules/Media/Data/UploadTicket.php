<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Shared\Data\DataTransferObject;
use Carbon\CarbonImmutable;

/**
 * Where the client uploads to, and how.
 *
 * One shape for both worlds: a commercial provider points this at its own host
 * so gigabytes never pass through PHP, and the local provider points it back at
 * us. Either way the client has a single upload path and does not change when
 * the provider does.
 *
 * Must never carry a provider key — only a token scoped to this one asset,
 * which expires.
 */
final class UploadTicket extends DataTransferObject
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly string $url,
        public readonly string $method,
        public readonly array $headers,
        public readonly array $fields,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
