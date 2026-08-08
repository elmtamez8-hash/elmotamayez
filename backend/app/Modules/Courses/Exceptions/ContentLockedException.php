<?php

declare(strict_types=1);

namespace App\Modules\Courses\Exceptions;

use DomainException;

/**
 * The node cannot be destroyed, but it can be archived.
 *
 * A distinct status because the answer is distinct. A 422 says "your request was
 * malformed"; this says "the request was fine, the thing is not yours to
 * destroy, and here is what to do instead". The alternative travels with the
 * refusal so the client does not have to know the rule — a refusal that leaves
 * the teacher guessing is a refusal that generates a support ticket.
 */
class ContentLockedException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $alternative = 'archive',
    ) {
        parent::__construct($message);
    }
}
