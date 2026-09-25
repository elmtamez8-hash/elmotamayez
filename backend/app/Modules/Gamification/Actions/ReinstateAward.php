<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Support\AwardChain;
use App\Shared\Actions\Action;

/**
 * A reversed award whose cause became true again ⇒ give back what was taken.
 *
 * The mirror of `ReverseAward`: a teacher marked a student absent and then back to
 * present, or a revision turned a pass into a fail and a second one turned it back.
 *
 * ⚠️ NOT A SECOND CALL TO `AwardPoints`. That would write a second
 * `reversal_of_id = 0` row for the same cause, which the unique key swallows as a
 * duplicate — the round trip used to end with the points gone for ever. And even
 * with a key that admitted it, it would pay today's catalogue price through
 * today's daily cap, not the amount that was taken back: a student whose reversal
 * could only claw back 2 of 5 coins would be paid 5 again, and a full cap would
 * refuse the restoration outright. The chain negates its own head instead
 * (`AwardChain`), so the cause sums to exactly one award.
 */
class ReinstateAward extends Action
{
    public function __construct(private readonly AwardChain $chain) {}

    /**
     * @return AwardEntry|null null when the cause is already paid
     */
    public function handle(AwardEntry $original): ?AwardEntry
    {
        return $this->chain->negateHead($original, fromHeld: false);
    }
}
