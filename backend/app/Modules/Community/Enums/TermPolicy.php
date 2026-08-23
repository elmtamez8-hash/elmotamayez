<?php

declare(strict_types=1);

namespace App\Modules\Community\Enums;

/**
 * What the filter does when a blocked term matches (FR-020).
 *
 * ⚠️ THE POLICY IS DECLARED PER TERM BECAUSE ONE ANSWER DOES NOT FIT. A slur is
 * refused outright; a phone number is masked so the message still says what it
 * meant; a borderline word is delivered and queued for a human. Collapsing these
 * into a single "block" is how a filter earns the reputation that makes people
 * route around it — and matching is on WORD BOUNDARIES for the same reason,
 * never on containment.
 */
enum TermPolicy: string
{
    /** The message is refused and the sender told why. */
    case Block = 'block';

    /** The term is replaced and the message goes through. */
    case Mask = 'mask';

    /** The message goes through and a moderation row is raised for a human. */
    case Review = 'review';
}
