<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Modules\Payments\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentApproved implements CarriesPaidOrder
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function order(): Order
    {
        return $this->order;
    }
}
