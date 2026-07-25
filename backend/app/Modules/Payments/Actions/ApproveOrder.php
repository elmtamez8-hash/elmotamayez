<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

class ApproveOrder extends Action
{
    use LogsActivity;

    public function handle(Order $order, User $approver): Order
    {
        if (! $order->isPending()) {
            throw new \DomainException('Only pending orders can be approved.');
        }

        return DB::transaction(function () use ($order, $approver): Order {
            $order->update([
                'status' => 'approved',
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ]);

            PaymentTransaction::create([
                'workspace_id' => $order->workspace_id,
                'order_id' => $order->getKey(),
                'provider' => $order->provider,
                'amount' => $order->amount,
                'currency' => $order->currency,
                'status' => 'captured',
                'reference' => 'manual-approval-'.$order->getKey(),
            ]);

            $order->refresh();

            event(new PaymentApproved($order));

            $this->logActivity('approved', $order, ['amount' => (float) $order->amount]);

            return $order;
        });
    }
}
