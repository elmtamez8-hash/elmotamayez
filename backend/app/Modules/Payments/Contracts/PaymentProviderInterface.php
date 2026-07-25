<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Models\Order;

/**
 * Abstraction over a payment provider (manual transfer, Paymob, Stripe, Fawry, PayPal, ...).
 *
 * New providers implement this interface and register themselves in the container.
 * Business logic (Order actions) depends on this interface, never on a concrete provider.
 */
interface PaymentProviderInterface
{
    /**
     * The provider's identifier (e.g. 'manual', 'paymob', 'stripe').
     */
    public function identifier(): string;

    /**
     * Initiate a charge for the given order. Returns provider-specific reference data.
     *
     * @return array<string, mixed>
     */
    public function createCharge(Order $order): array;

    /**
     * Verify a charge status with the provider (for gateway/webhook flows).
     *
     * @param  array<string, mixed>  $reference
     * @return array{status: string, verified: bool}
     */
    public function verify(array $reference): array;

    /**
     * Whether this provider supports automated refunds (MVP manual transfer does not).
     */
    public function supportsRefund(): bool;
}
