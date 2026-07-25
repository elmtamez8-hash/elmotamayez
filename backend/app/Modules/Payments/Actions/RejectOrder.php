<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Events\PaymentRejected;
use App\Modules\Payments\Models\Order;
use App\Shared\Actions\Action;

class RejectOrder extends Action
{
    public function handle(Order $order, User $approver, string $reason): Order
    {
        if (! $order->isPending()) {
            throw new \DomainException('Only pending orders can be rejected.');
        }

        $order->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => $approver->getKey(),
        ]);

        event(new PaymentRejected($order->fresh()));

        return $order->fresh();
    }
}
