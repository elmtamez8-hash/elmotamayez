<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Data;

use App\Shared\Data\DataTransferObject;

/**
 * One autosave of one page (R-08): the scene as TEXT (stored as it arrived), the
 * version it was edited from, and the tab's idempotency key `(tab, clientRev)`.
 */
final class SceneData extends DataTransferObject
{
    public function __construct(
        public readonly string $tab,
        public readonly int $version,
        public readonly int $clientRev,
        public readonly string $scene,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            tab: (string) $data['tab'],
            version: (int) $data['version'],
            clientRev: (int) $data['client_rev'],
            scene: (string) $data['scene'],
        );
    }
}
