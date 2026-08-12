<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Approval is what mints credits — nothing before it does.
 *
 * `ShouldHandleEventsAfterCommit` is not decoration: ApproveOrder fires
 * PaymentApproved from inside its own transaction, so without it a queued worker
 * can pick the job up before the approval is committed and read an order that,
 * from its connection, is still pending — or has rolled back entirely.
 *
 * Course orders are ignored here, and credit orders are ignored by
 * {@see CreateEnrollmentFromOrder}. The two listeners share one event and split
 * on `orders.kind`; without the split, buying credits would also enrol the
 * student in the whole course for nothing.
 *
 * ⚠️ No WorkspaceContext::set() — this is a queue worker. Everything it needs is
 * on the rows: the balance carries its own workspace_id, written explicitly when
 * it was created.
 */
class CreditPurchaseOnApproval implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(private readonly RecordCreditPurchase $record) {}

    /**
     * ⚠️ Typed on the CONTRACT, not on PaymentApproved. Both the manual approval
     * and the gateway capture mint credits, and a class type here threw a
     * TypeError on the first successful gateway payment.
     */
    public function handle(CarriesPaidOrder $event): void
    {
        $order = $event->order();

        if ($order->kind !== OrderKind::Credits) {
            return;
        }

        $this->record->handle($order);
    }
}
