<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Shared\Data\DataTransferObject;

class AddChildData extends DataTransferObject
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $age,
        public readonly ?string $gradeLevelSlug,
        /** An existing student account to attach, by uuid. Null is the normal case
         * at signup: the child has no account yet (FR-074). */
        public readonly ?string $childUuid,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            age: isset($data['age']) ? (int) $data['age'] : null,
            gradeLevelSlug: isset($data['grade_level_slug']) ? (string) $data['grade_level_slug'] : null,
            childUuid: isset($data['child_uuid']) ? (string) $data['child_uuid'] : null,
        );
    }
}
