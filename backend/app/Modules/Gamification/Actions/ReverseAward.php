<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Support\AwardChain;
use App\Shared\Actions\Action;

/**
 * Undo an award whose cause turned out to be false (FR-010).
 *
 * A NEW, NEGATIVE ENTRY pointing at the original — never an update, never a
 * delete. `AwardEntry::booted()` refuses both, the same shape as Settlement's
 * ledger: the history of what a student was told they earned is part of the
 * record, and a correction that erases it leaves the student's screen and the
 * teacher's memory permanently disagreeing.
 *
 * ⚠️ THE REVERSAL CARRIES `reversal_of_id`, AND THAT COLUMN IS INSIDE THE
 * IDEMPOTENCY KEY. This is the whole reason the mechanism works at all. The
 * reversal repeats the original's student, action, source_type and source_id — so
 * without a fifth column it would collide with the entry it is reversing,
 * insertOrIgnore would write zero rows, the read-back would find the ORIGINAL,
 * the code would conclude "already recorded" and report success — and the points
 * would never be returned. To anyone watching, FR-010 would be implemented.
 *
 * ⚠️ AND THE COLUMN IS `NOT NULL DEFAULT 0`, NOT NULLABLE. `NULL != NULL` in a
 * unique index on both engines, so a nullable discriminator would stop the guard
 * biting for every ORDINARY award instead — the same event paying twice. Zero
 * compares; the sentinel is the fix. (Precedent: `concept_stats.lesson_id`.)
 *
 * ⚠️ A CAUSE CAN BE REVERSED, REINSTATED AND REVERSED AGAIN — a mark changed to
 * absent and back, a pass revised into a fail and back. Each step is a row that
 * negates the one before it; `AwardChain` holds the whole mechanism, and
 * `ReinstateAward` is the other direction.
 */
class ReverseAward extends Action
{
    public function __construct(private readonly AwardChain $chain) {}

    /**
     * Name the cause by its ORIGINAL entry. What is actually negated is the
     * chain's current head (see `AwardChain`): after a reinstatement that is the
     * reinstatement, and a second reversal of the same cause is written against it.
     *
     * @return AwardEntry|null null when the cause is already reversed
     */
    public function handle(AwardEntry $original): ?AwardEntry
    {
        return $this->chain->negateHead($original, fromHeld: true);
    }
}
