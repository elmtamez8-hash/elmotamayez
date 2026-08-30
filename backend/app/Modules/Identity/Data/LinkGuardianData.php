<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Support\RelationType;
use App\Shared\Data\DataTransferObject;
use App\Shared\Support\GuardianPermission;

class LinkGuardianData extends DataTransferObject
{
    /** @param list<GuardianPermission> $permissions */
    public function __construct(
        public readonly string $studentName,
        public readonly ?int $studentAge,
        public readonly ?string $schoolYearSlug,
        /** An existing student account to attach, by uuid. Null is the normal case
         * at signup: the child has no account yet. */
        public readonly ?string $studentUuid,
        public readonly RelationType $relationType,
        public readonly array $permissions,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $rawPermissions */
        $rawPermissions = $data['permissions'] ?? GuardianPermission::values();

        return new self(
            studentName: (string) ($data['student_name'] ?? $data['name'] ?? ''),
            studentAge: isset($data['age']) ? (int) $data['age'] : null,
            schoolYearSlug: isset($data['school_year_slug']) ? (string) $data['school_year_slug'] : null,
            studentUuid: isset($data['student_uuid']) ? (string) $data['student_uuid'] : null,
            relationType: RelationType::from((string) ($data['relation_type'] ?? RelationType::Parent->value)),
            permissions: array_values(array_filter(array_map(
                static fn (string $value): ?GuardianPermission => GuardianPermission::tryFrom($value),
                $rawPermissions,
            ))),
        );
    }
}
