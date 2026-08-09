<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Credits were added against payment. Consumed by the student notification and,
 * later, by spec 015's books.
 *
 * Carries the purchase as well as the ledger entry, because the purchase is
 * where the four price components live and the books are generated from those.
 *
 * Dispatched AFTER commit, never inside the transaction: telling a student they
 * have credits on a write that then rolled back is worse than silence.
 */
class CreditsPurchased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditTransaction $transaction,
        public readonly CreditPurchase $purchase,
    ) {}
}
