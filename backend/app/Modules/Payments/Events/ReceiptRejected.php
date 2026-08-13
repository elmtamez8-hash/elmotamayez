<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Models\User;
use App\Modules\Payments\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A human read the receipt and refused it (FR-020).
 *
 * The reason travels with the event because a rejection the payer cannot read is
 * a rejection they will repeat: the same transfer, re-uploaded, refused again.
 */
class ReceiptRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly User $approver,
        public readonly string $reason,
    ) {}
}
