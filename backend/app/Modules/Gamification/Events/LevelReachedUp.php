<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student crossed a level threshold (FR-012).
 *
 * ⚠️ RAISED AFTER COMMIT. An event fired inside the transaction announces a row
 * that may still be rolled back — and on a real queue the job can start before
 * the commit lands and read nothing at all.
 *
 * ⚠️ AND IT FIRES ONCE PER LEVEL, EVER. The claim is a conditional UPDATE on
 * `notified_level`, not a comparison in PHP: experience can go down, so a level
 * recomputed and announced unconditionally would congratulate the same student
 * for the same level every time they re-crossed it.
 */
class LevelReachedUp
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $student,
        public readonly int $level,
        public readonly string $levelNameAr,
    ) {}
}
