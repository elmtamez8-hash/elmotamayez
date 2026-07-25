<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\DTOs;

use App\Shared\Data\DataTransferObject;

class CreateWorkspaceDTO extends DataTransferObject
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $slug = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $settings = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'],
            slug: $data['slug'] ?? null,
            settings: $data['settings'] ?? null,
        );
    }
}
