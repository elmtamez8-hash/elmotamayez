<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One seat was charged for one delivered session. Consumed by spec 009's
 * gamification and spec 015's books.
 *
 * One event per SEAT, not one per session: the charge runs in its own
 * transaction per seat, so that a refusal for one student cannot drop the other
 * twenty-nine, and the event follows the same grain as the work.
 */
class CreditConsumed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditTransaction $transaction,
        public readonly int $classSessionId,
    ) {}
}
