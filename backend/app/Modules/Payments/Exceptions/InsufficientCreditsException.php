<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use DomainException;

/**
 * The floor refused a movement.
 *
 * A domain refusal, not a failure: the balance is where it was and the entry
 * that was about to be written is rolled back with it. Raised only where the
 * floor is enforced — booking — and never on the recording of a debt already
 * incurred (data-model §5هـ).
 */
class InsufficientCreditsException extends DomainException {}
