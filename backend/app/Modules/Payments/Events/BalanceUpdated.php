<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditBalance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The fourth link in the declared chain.
 *
 * SC-006 requires an integration test that OBSERVES a four-event chain —
 * SessionDelivered → ChargeSessionSeats → CreditConsumed → BalanceUpdated — and
 * this link appeared in no emit list until the design was reviewed. A success
 * criterion that names an event nobody dispatches is a criterion that can only
 * be met by rewriting it.
 *
 * Carries the balance after the write, plus the delta, so a listener does not
 * have to re-read the row to know which way it moved.
 */
class BalanceUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditBalance $balance,
        public readonly int $deltaCredits,
    ) {}
}
