<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Models\StoreOrder;
use App\Shared\Actions\Action;

/**
 * Deliver what was paid for (spec 011 · US1 · FR-006).
 *
 * ⚠️ THE FIRST STATEMENT IS THE CLAIM, AND EVERYTHING AFTER IT IS CONDITIONAL ON
 * HAVING WON. `PaymentApproved` is redelivered by any queue retry, and a
 * read-then-write here takes two copies off the shelf for one sale — with the
 * duplicate invisible until somebody counts the books. `UPDATE … WHERE
 * fulfilled_at IS NULL` is both the check and the claim; never
 * `lockForUpdate()`, a no-op on SQLite.
 *
 * ⚠️ AND THE SOLD-OUT BRANCH IS NOT AN EDGE CASE (FR-006ب). Days separate the
 * purchase from the approval of a manual bank transfer, so the last copy going
 * in between is ordinary. «Zero rows, you lost, nothing follows» is the wrong
 * last word about an order whose money has been taken: the order becomes
 * `refund_due`, the buyer is told, and an operator has something to act on.
 */
class FulfilStorePurchase extends Action
{
    public function __construct(
        private readonly ClaimStock $stock,
        private readonly DispatchNotification $notify,
    ) {}

    public function handle(Order $order): void
    {
        $purchase = StoreOrder::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $order->getKey())
            ->first();

        if ($purchase === null) {
            return;
        }

        // The claim. A second delivery of the same event stops here.
        $claimed = StoreOrder::query()
            ->withoutWorkspaceScope()
            ->whereKey($purchase->getKey())
            ->whereNull('fulfilled_at')
            ->update(['fulfilled_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $purchase->refresh();

        $item = $purchase->item()->withoutWorkspaceScope()->first();

        if ($item === null) {
            return;
        }

        if ($this->stock->handle($item, $purchase->quantity)) {
            // A digital item needs nothing further: the entitlement IS the
            // fulfilled row, and `IssueStoreAccess` reads it. A printed one
            // already has its `pending` shipment, written at purchase with the
            // address the buyer typed while they were still at the screen.
            return;
        }

        $this->markRefundDue($order, $purchase, (string) $item->title);
    }

    /**
     * ⚠️ THE FULFILMENT STAMP IS RELEASED AGAIN. It was claimed to make this
     * path run exactly once, and leaving it set on an order that delivered
     * nothing would let `IssueStoreAccess` open a file nobody has a copy of —
     * and would tell a later reader the parcel was on its way.
     */
    private function markRefundDue(Order $order, StoreOrder $purchase, string $title): void
    {
        StoreOrder::query()
            ->withoutWorkspaceScope()
            ->whereKey($purchase->getKey())
            ->update(['fulfilled_at' => null]);

        /*
        | The status lives on `orders`, not on the bridge row: an order's
        | lifecycle belongs to Payments, and two columns answering «where has
        | this order got to» are the two answers that drift apart.
        |
        | ⚠️ AND THE TRANSITION IS CONDITIONAL, WHICH IS WHAT MAKES THE MESSAGE
        | ARRIVE ONCE. `fulfilled_at` was released two lines up, so a redelivered
        | `PaymentApproved` re-claims it, fails on the shelf again, and reaches
        | here again — telling the buyer their order is unavailable every time the
        | queue retries. The `WHERE` is both the check and the claim, exactly as
        | `AdvanceShipment` guards its own notification.
        */
        $moved = Order::query()
            ->whereKey($order->getKey())
            ->where('status', '!=', 'refund_due')
            ->update(['status' => 'refund_due']);

        if ($moved === 0) {
            return;
        }

        $buyer = $purchase->buyer()->first();

        if ($buyer === null) {
            return;
        }

        $this->notify->handle(new NotificationRequest(
            recipient: $buyer,
            type: NotificationType::StorePurchaseUnavailable,
            // ⚠️ NO PRICE. A store total minus the published commission is the
            // teacher's net, and neither side may be handed the other's number.
            variables: ['item_title' => $title],
            actionUrl: '/store/purchases',
            workspaceId: (int) $purchase->workspace_id,
            sourceType: StoreOrder::class,
            sourceId: (int) $purchase->getKey(),
        ));
    }
}
