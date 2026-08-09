<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditBalance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A balance fell past an alert threshold. Consumed by spec 003's notifications.
 *
 * Fired on the DOWNWARD transition only. The rank reached is compared against
 * `notified_tier` inside the same transaction that moved the balance, and rising
 * back up resets the rank — which is what makes "the same alert never repeats
 * for the same crossing" (FR-034) true without a de-duplication table.
 */
class BalanceThresholdCrossed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditBalance $balance,
        public readonly int $tier,
    ) {}
}
