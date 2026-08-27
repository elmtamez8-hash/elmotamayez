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
        /** Minor units, always an integer. A float price is the defect 007 removed. */
        public readonly int $priceMinor = 0,
        public readonly string $currency = 'QAR',
        public readonly string $status = 'draft',
        public readonly string $visibility = 'private',
        public readonly bool $isSequential = true,
        /** The platform-wide subject uuid; resolved to an id in the Action. */
        public readonly ?string $subjectUuid = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            description: $data['description'] ?? null,
            slug: $data['slug'] ?? null,
            priceMinor: (int) ($data['price_minor'] ?? 0),
            currency: $data['currency'] ?? 'QAR',
            status: $data['status'] ?? 'draft',
            visibility: $data['visibility'] ?? 'private',
            isSequential: $data['is_sequential'] ?? true,
            subjectUuid: isset($data['subject']) && is_string($data['subject']) ? $data['subject'] : null,
        );
    }
}
