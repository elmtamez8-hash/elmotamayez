<?php

declare(strict_types=1);

namespace App\Modules\Store\Listeners;

use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Modules\Store\Actions\FulfilStorePurchase;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Approval is what delivers a store purchase — nothing before it does.
 *
 * ⚠️ `ShouldHandleEventsAfterCommit` IS NOT DECORATION, and without it this
 * listener SUCCEEDS while doing nothing. `ApproveOrder` fires the event from
 * inside its own transaction, so a worker can pick the job up before the commit
 * and read an order that is still `pending` from its own connection — the stock
 * is never claimed, the buyer is never told, and there is no failure anywhere.
 *
 * ⚠️ AND `ShouldQueue`, WHICH IS THE OTHER HALF. A plain-class listener runs
 * inside the Action that dispatched the event, so a throw here would propagate
 * back and kill every line after it — the defect `AccrueUnitsOnDelivery` cost
 * this repository once already, permanently and unrepeatably.
 *
 * ⚠️ Typed on the CONTRACT rather than on `PaymentApproved`: both the manual
 * approval and the gateway capture carry a paid order, and a class type here
 * threw a TypeError on the first successful gateway payment.
 *
 * ⚠️ No `WorkspaceContext::set()` — this is a queue worker, and the singleton
 * would leak into whatever that worker handles next. Everything needed is on the
 * rows: the bridge row carries its own `workspace_id`, written explicitly.
 */
class FulfilOnPaymentApproved implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(private readonly FulfilStorePurchase $fulfil) {}

    public function handle(CarriesPaidOrder $event): void
    {
        $order = $event->order();

        if ($order->kind !== OrderKind::Store) {
            return;
        }

        $this->fulfil->handle($order);
    }
}
