<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Models\User;
use App\Modules\Payments\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payer attached proof of a transfer they made themselves (FR-020).
 *
 * ⚠️ ADDED BESIDE `PaymentApproved`, never in place of it. The two answer
 * different questions and have different audiences: `PaymentApproved` says money
 * was accepted and is what enrols a student and mints credits, while these three
 * describe the RECEIPT's own journey — which is what an operator's queue and the
 * audit trail are built from. Renaming the old one would have silently
 * unsubscribed every listener the enrolment chain depends on.
 */
class ReceiptUploaded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly User $uploader,
    ) {}
}
