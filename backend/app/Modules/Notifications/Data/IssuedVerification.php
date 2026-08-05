<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Modules\Notifications\Models\ContactVerification;
use App\Shared\Data\DataTransferObject;

/**
 * The record, plus the one-time code in the clear.
 *
 * The code travels beside the model rather than on it: an attribute would be
 * dirty on the next save, and the whole point is that it never touches the
 * database in readable form.
 */
final class IssuedVerification extends DataTransferObject
{
    public function __construct(
        public readonly ContactVerification $verification,
        public readonly string $code,
    ) {}
}
