<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/**
 * Entitled, classified high value, and the balance is in the red (FR-042).
 *
 * A distinct exception because the answer is distinct. "لا تملك صلاحية" is what
 * a stranger gets, and it is the wrong sentence for a student who is enrolled,
 * whose sessions are open, and who is one payment away — a refusal that leaves
 * them guessing is a refusal that generates a support ticket instead of a
 * payment. Precedent: `Courses\Exceptions\ContentLockedException`, which carries
 * its alternative for the same reason.
 *
 * ⚠️ THE NUMBER COMES FROM THE CONTRACT, not from a balance read here.
 * `AccountStanding::creditsNeededFor()` exists precisely so a refuser outside
 * Payments can state the amount without being allowed to see the balance it was
 * computed from. Only the number crosses the boundary; this class turns it into
 * a sentence.
 *
 * Extends RuntimeException so an unprepared caller still refuses. It must be
 * caught BEFORE the generic RuntimeException, or the reason is flattened back
 * into the bare 403 this class exists to avoid.
 */
class AccessWithheldException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $creditsNeeded,
        public readonly string $courseUuid,
    ) {
        parent::__construct($message);
    }
}
