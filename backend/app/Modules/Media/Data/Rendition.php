<?php

declare(strict_types=1);

namespace App\Modules\Media\Data;

use App\Shared\Data\DataTransferObject;

/**
 * One quality level the provider actually produced.
 *
 * A provider that claims adaptive bitrate must return at least two of these —
 * enforced by the contract test, so the claim cannot be decorative.
 */
final class Rendition extends DataTransferObject
{
    public function __construct(
        public readonly string $label,
        public readonly int $height,
        public readonly ?int $bitrateKbps = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) $data['label'],
            height: (int) $data['height'],
            bitrateKbps: isset($data['bitrate_kbps']) ? (int) $data['bitrate_kbps'] : null,
        );
    }
}
