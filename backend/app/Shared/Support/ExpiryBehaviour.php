<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Contracts\PersonalDataOwner;

/**
 * What the nightly sweep does to a row whose retention has run out
 * (spec 013 · FR-028 … FR-031).
 *
 * ⚠️ A DIFFERENT QUESTION FROM {@see ErasureMode}, and merging them was the
 * temptation worth naming. Erasure acts on ONE PERSON who asked; expiry acts on
 * EVERY ROW older than a category's retention, for everybody, with nobody asking.
 * `Retain` is meaningless here — a category whose rows are never processed simply
 * has a null retention — and `Archive` is meaningless there, because a subject
 * exercising their right to be forgotten is not answered by moving their data
 * somewhere else.
 *
 * @see PersonalDataOwner::expire()
 */
enum ExpiryBehaviour: string
{
    /** The row goes. */
    case Delete = 'delete';

    /** The row stays and stops pointing at anyone — see {@see ErasureMode::Anonymise}. */
    case Anonymise = 'anonymise';

    /**
     * The row is marked as archived and stops being served.
     *
     * ⚠️ IT NEEDS A COLUMN, AND WITHOUT ONE THE SWEEP NEVER CONVERGES. A predicate
     * of the shape "older than N days" is true again tomorrow, so a behaviour with
     * no mark re-archives the same rows every night for ever — and counts them
     * again each time in "how many rows did I process", which makes the run log
     * lie in the same breath. Precedent, in the same words:
     * `notified_dormant_at`.
     */
    case Archive = 'archive';
}
