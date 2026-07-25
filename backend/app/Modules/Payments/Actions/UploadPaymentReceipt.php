<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Models\Order;
use App\Shared\Actions\Action;
use Illuminate\Http\UploadedFile;

class UploadPaymentReceipt extends Action
{
    public function handle(Order $order, UploadedFile $file, User $user): Order
    {
        if ($order->user_id !== $user->getKey()) {
            throw new \DomainException('You can only upload receipts for your own orders.');
        }

        $order->addMedia($file)->toMediaCollection('receipt');

        if ($order->status === 'pending') {
            $order->update(['status' => 'under_review']);
        }

        return $order->fresh();
    }
}
