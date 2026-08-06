<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

use App\Modules\Settlement\Models\TeachingUnit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit stopped being pending and became money the teacher is owed.
 *
 * Fired at the transition, not at creation: a unit waiting for its recording is
 * not yet an earning, and a listener that treated it as one would put a figure
 * in front of the teacher that can still go away.
 */
class TeachingUnitAccrued
{
    use Dispatchable;

    public function __construct(
        public readonly TeachingUnit $unit,
    ) {}
}
