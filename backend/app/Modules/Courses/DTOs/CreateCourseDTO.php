<?php

declare(strict_types=1);

namespace App\Modules\Courses\DTOs;

use App\Shared\Data\DataTransferObject;

class CreateCourseDTO extends DataTransferObject
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $slug = null,
        public readonly float $price = 0.0,
        public readonly string $currency = 'USD',
        public readonly string $status = 'draft',
        public readonly string $visibility = 'private',
        public readonly bool $isSequential = true,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            description: $data['description'] ?? null,
            slug: $data['slug'] ?? null,
            price: (float) ($data['price'] ?? 0),
            currency: $data['currency'] ?? 'USD',
            status: $data['status'] ?? 'draft',
            visibility: $data['visibility'] ?? 'private',
            isSequential: $data['is_sequential'] ?? true,
        );
    }
}
