<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Shared\Data\DataTransferObject;

class RegisterParentData extends DataTransferObject
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $password,
        public readonly string $phone,
        public readonly string $country,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: (string) $data['first_name'],
            lastName: (string) ($data['last_name'] ?? ''),
            email: (string) $data['email'],
            password: (string) $data['password'],
            country: strtoupper((string) $data['country']),
            phone: (string) $data['phone'],
        );
    }
}
