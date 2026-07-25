<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Models\Order;

/**
 * Manual bank-transfer provider.
 *
 * The student uploads a receipt; a workspace approver manually approves the order.
 * createCharge returns a reference instructing the student where to wire funds.
 * verify always returns false (no automated gateway verification).
 */
final class ManualTransferProvider implements PaymentProviderInterface
{
    public function identifier(): string
    {
        return 'manual';
    }

    public function createCharge(Order $order): array
    {
        return [
            'method' => 'bank_transfer',
            'instructions' => 'Transfer the exact amount to the workspace bank account and upload the receipt.',
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
        ];
    }

    public function verify(array $reference): array
    {
        // Manual transfers are verified by a human approver, not by a gateway callback.
        return ['status' => 'manual_review', 'verified' => false];
    }

    public function supportsRefund(): bool
    {
        return false;
    }
}
