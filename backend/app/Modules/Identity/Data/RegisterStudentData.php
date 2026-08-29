<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Shared\Data\DataTransferObject;

class RegisterStudentData extends DataTransferObject
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $password,
        public readonly string $phone,
        public readonly string $country,
        public readonly string $gradeLevelSlug,
        public readonly bool $registeredByParent,
        /*
        | Spec 013. Nullable on the DTO and REQUIRED by the form request, so a
        | seeder or a test can still build an account without one — the guard that
        | matters is the one on the way in, and `FR-009ج` says an unknown date is a
        | real state the product must handle rather than refuse.
        */
        public readonly ?string $dateOfBirth = null,
        public readonly ?string $guardianContact = null,
        /*
        | Spec 011 · FR-042. Nullable here and required by the form request, the
        | same asymmetry the date of birth above carries and for the same reason:
        | a seeder or a test builds accounts that predate the question, and the
        | column keeps NULL meaningful for them.
        */
        public readonly ?string $regionSlug = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: (string) $data['first_name'],
            lastName: (string) ($data['last_name'] ?? ''),
            email: (string) $data['email'],
            password: (string) $data['password'],
            phone: (string) $data['phone'],
            country: strtoupper((string) $data['country']),
            gradeLevelSlug: (string) $data['grade_level_slug'],
            registeredByParent: (bool) ($data['registered_by_parent'] ?? false),
            dateOfBirth: isset($data['date_of_birth']) ? (string) $data['date_of_birth'] : null,
            guardianContact: isset($data['guardian_contact']) ? (string) $data['guardian_contact'] : null,
            regionSlug: isset($data['region_slug']) ? (string) $data['region_slug'] : null,
        );
    }
}
