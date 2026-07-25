<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}
}
