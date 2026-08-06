<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Events;

use App\Modules\Settlement\Models\SettlementPeriod;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A period's totals stopped moving.
 *
 * Consumed here to notify the teacher, and by 012 later to trigger the automated
 * transfer. It carries the period rather than the figures: the figures are frozen
 * on the row by the time this fires, so a copy in the payload could only ever
 * disagree with it.
 */
class SettlementPeriodClosed
{
    use Dispatchable;

    public function __construct(
        public readonly SettlementPeriod $period,
    ) {}
}
