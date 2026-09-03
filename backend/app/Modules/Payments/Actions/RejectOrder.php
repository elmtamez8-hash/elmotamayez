<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Events\PaymentRejected;
use App\Modules\Payments\Events\ReceiptRejected;
use App\Modules\Payments\Models\Order;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * A human reads the receipt and refuses it.
 *
 * ⚠️ THE SAME ATOMIC CLAIM AS {@see ApproveOrder}, and the pair is why they were
 * written in one change. The race that matters is not two rejections — it is an
 * approval and a rejection meeting on one order: the approval enrols a student
 * and mints credits, and there is no compensating path that takes either back.
 * `WHERE status IN (pending, under_review)` on both means exactly one decision
 * lands and the other is told so before it does anything.
 */
class RejectOrder extends Action
{
    use LogsActivity;

    public function handle(
        Order $order,
        User $approver,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Order {
        /*
        | ⚠️ `withoutWorkspaceScope()` — the same third layer as `ApproveOrder`.
        |
        | The claim is a conditional UPDATE through the model, so the global scope
        | ANDs the CURRENT workspace onto it, and a platform officer's context
        | falls back to `users.last_workspace_id` like anybody else's. An officer
        | who also owns a workspace matched zero rows on every order outside it
        | and was told the order was not pending, about an order that was.
        |
        | Safe because `OrderPolicy` ran above this call and asks the workspace
        | question itself on every non-platform branch. Measured 2026-09-03 (024).
        */
        $claimed = Order::query()
            ->withoutWorkspaceScope()
            ->whereKey($order->getKey())
            ->whereIn('status', ['pending', 'under_review'])
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'approved_by' => $approver->getKey(),
            ]);

        if ($claimed === 0) {
            throw new DomainException('Only pending orders can be rejected.');
        }

        $order->refresh();

        event(new PaymentRejected($order));

        ReceiptRejected::dispatch($order, $approver, $reason);

        $this->logActivity('rejected', $order, [
            'amount_minor' => $order->amount_minor,
            'reason' => $reason,
            // FR-024. A refusal is a financial decision as much as an approval
            // is, and this Action recorded none of it before — the trail showed
            // every acceptance and no refusal at all.
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);

        return $order;
    }
}
