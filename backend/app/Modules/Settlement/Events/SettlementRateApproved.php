<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

use App\Modules\Settlement\Models\SettlementRate;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new settlement rate took effect.
 *
 * **This event has no listener yet, and that is recorded rather than forgotten.**
 * Its consumer is the cost-plus pricing in spec 006: the approved teacher rate is
 * an input to the sale price, so approving one reprices NEW packages — and must
 * never touch a purchase already made or credits already held (FR-013ج).
 *
 * The precedent for shipping an event ahead of its consumer is `SessionDelivered`
 * in spec 005, which this module now consumes precisely because 005 declared it
 * and wrote down who it was for. The counter-example is in the same phase:
 * `SessionCancelled` shipped with no listener and no note, and it took a review
 * to notice. The difference between the two was a written line, not code.
 *
 * @see specs/014-teacher-settlement/contracts/events.md
 */
class SettlementRateApproved
{
    use Dispatchable;

    public function __construct(
        public readonly SettlementRate $rate,
    ) {}
}
