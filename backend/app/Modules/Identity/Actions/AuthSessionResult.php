<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AuthSession;
use App\Shared\Data\DataTransferObject;

/**
 * What a successful sign-in produces.
 *
 * The session uuid goes to the client so it can ask why it was signed out later,
 * once the token is gone and no authenticated call is possible any more.
 */
final class AuthSessionResult extends DataTransferObject
{
    public function __construct(
        public readonly AuthSession $session,
        public readonly string $plainTextToken,
    ) {}
}
