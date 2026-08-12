<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The provider refused the payment, or it timed out.
 *
 * `$reason` is the sentence the STUDENT reads (FR-008) — already translated by
 * whoever raised it, never the provider's raw error string, which names systems
 * the student has no relationship with.
 */
class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly PaymentTransaction $transaction,
        public readonly ?string $reason = null,
    ) {}
}
