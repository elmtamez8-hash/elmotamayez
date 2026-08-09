<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Credits were returned. Awaited by spec 007, which turns it into cash.
 *
 * ⚠️ A refund LOWERS both `remaining` and `purchased`, and never touches
 * `consumed`. An earlier note here said it raised `remaining` while lowering
 * `purchased`; those move in opposite directions and cannot both be true under
 * remaining = purchased − consumed. The student hands credits back and receives
 * cash, so the credits leave — this is the reverse of a purchase. Raising
 * `consumed` instead would keep the invariant true while showing the student
 * sessions they never attended, which is the drift SC-001 cannot see.
 *
 * The ledger entry is keyed by its OWN identity (`source_type = credit_refund`),
 * not by the purchase it reverses: keyed by the purchase, a second partial
 * refund of the same purchase would collide with the first on the idempotency
 * index, be read as a duplicate, and be reported as a success while returning
 * nothing.
 */
class RefundIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditTransaction $transaction,
    ) {}
}
