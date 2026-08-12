<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payment succeeded at the provider and the platform recorded it.
 *
 * ⚠️ IT CARRIES THE ORDER EXPLICITLY, and that is not redundancy with the
 * transaction. The two shipped listeners — the one that enrols a student and the
 * one that mints credits — are typed on an event that has an `$order` property,
 * and this event is bound to both. Reaching it through `$transaction->order`
 * would work and would also mean every listener holds a lazy relation it must
 * remember to load; the event states what it is about.
 *
 * Fired AFTER the transaction commits, never inside it: a listener that enrols a
 * student on a payment whose write then rolls back has enrolled them for free.
 */
class PaymentCaptured implements CarriesPaidOrder
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly PaymentTransaction $transaction,
    ) {}

    public function order(): Order
    {
        return $this->order;
    }
}
