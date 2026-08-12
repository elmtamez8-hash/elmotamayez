<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events\Contracts;

use App\Modules\Payments\Models\Order;

/**
 * "An order was paid for" — the one thing enrolment and credit minting care
 * about, however the money arrived.
 *
 * ⚠️ THIS EXISTS BECAUSE TWO SHIPPED LISTENERS WERE TYPED ON A CLASS. They took
 * `PaymentApproved` — the manual, human-approved path — and binding them to
 * `PaymentCaptured` as well would have thrown a TypeError on the first
 * successful gateway payment. One of them, CreateEnrollmentFromOrder, is the
 * eighth critical path in the constitution: breaking it stops the merge.
 *
 * A union type would have worked and would also have meant every future payment
 * route editing both listeners. The contract is the smaller thing: a new event
 * implements it and the listeners never change.
 *
 * The property stays public on both events — nothing has to change at the call
 * sites that already read `$event->order`.
 */
interface CarriesPaidOrder
{
    public function order(): Order;
}
