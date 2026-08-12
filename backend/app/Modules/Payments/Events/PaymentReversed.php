<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A captured payment was taken back — a chargeback or a bank reversal.
 *
 * Distinct from a refund, which the platform issues as credits and which never
 * touches this path: a reversal is imposed from outside, and the access it had
 * unlocked has to be reconsidered.
 */
class PaymentReversed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly PaymentTransaction $transaction,
        public readonly ?string $reason = null,
    ) {}
}
