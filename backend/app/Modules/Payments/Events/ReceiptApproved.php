<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Models\User;
use App\Modules\Payments\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A human read the receipt and accepted it (FR-020).
 *
 * Fired beside `PaymentApproved` from the same Action and inside the same
 * transaction's after-commit window. It carries the APPROVER, which
 * `PaymentApproved` deliberately does not: the enrolment chain has no business
 * knowing who signed off, and the audit trail has no business guessing.
 */
class ReceiptApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly User $approver,
    ) {}
}
