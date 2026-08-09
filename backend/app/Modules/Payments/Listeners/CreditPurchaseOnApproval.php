<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\PaymentApproved;
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

    public function handle(PaymentApproved $event): void
    {
        if ($event->order->kind !== OrderKind::Credits) {
            return;
        }

        $this->record->handle($event->order);
    }
}
