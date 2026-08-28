<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Shared\Data\DataTransferObject;

class RegisterAccountData extends DataTransferObject
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $password,
        /*
        | Optional, and deliberately so: an academy founder registers with nothing
        | in hand. See RegisterAccount's docblock.
        */
        public readonly ?string $invitationToken = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $token = $data['invitation'] ?? null;

        return new self(
            firstName: (string) $data['first_name'],
            lastName: (string) ($data['last_name'] ?? ''),
            email: (string) $data['email'],
            password: (string) $data['password'],
            invitationToken: $token === null || $token === '' ? null : (string) $token,
        );
    }
}
