<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Data\ChargeIntent;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Manual bank-transfer provider.
 *
 * The student wires the money and uploads a receipt; a workspace approver
 * confirms it by hand. There is no gateway, no callback and no signature — and
 * the three methods below say so by refusing, not by pretending.
 */
final class ManualTransferProvider implements PaymentProviderInterface
{
    public function identifier(): string
    {
        return 'manual';
    }

    /**
     * ⚠️ `$returnUrl` IS IGNORED HERE, AND THE PARAMETER STAYS. A bank transfer
     * has no payment page, so there is nowhere to return FROM — the payer never
     * left. Dropping it from the signature would make this class stop
     * implementing the interface; taking it and saying nothing would leave the
     * next reader wondering which of the two it is.
     */
    public function createCharge(Order $order, string $returnUrl): ChargeIntent
    {
        return new ChargeIntent(
            // ⚠️ A FRESH REFERENCE PER ATTEMPT, never the order's uuid. The
            // reference is unique per provider, so an order whose first transfer
            // was never completed could never be paid a second time — the same
            // break a bare unique(order_id) would have caused on captures.
            reference: 'MT-'.strtoupper(Str::random(10)),
            method: PaymentMethod::BankTransfer,
            amountMinor: $order->amount_minor,
            currency: $order->currency,
            // No payment page: a wire is made in the payer's own bank.
            redirectUrl: null,
            instructions: 'حوّل المبلغ كاملاً إلى حساب الأكاديمية البنكي ثم ارفع صورة الإيصال.',
        );
    }

    /** @param array<string, mixed> $reference */
    public function verify(array $reference): array
    {
        // Verified by a human approver, never by a gateway callback.
        return ['status' => 'manual_review', 'verified' => false];
    }

    /**
     * ⚠️ ALWAYS FALSE, AND THAT IS THE IMPLEMENTATION — not a stub awaiting a
     * key. This provider sends no callbacks, so anything arriving in its name is
     * an impersonation, and the only correct answer to "did this come from the
     * manual provider" is no. A permissive default here would be an unsigned,
     * unauthenticated write path into the payment ledger.
     *
     * @param  array<string, string>  $headers
     */
    public function verifySignature(string $rawBody, array $headers): bool
    {
        return false;
    }

    /**
     * Unreachable by construction: nothing gets past verifySignature(). It
     * throws rather than returning an empty event, because an empty event is a
     * payment record with no payment behind it.
     */
    public function parseCallback(string $rawBody): CallbackEvent
    {
        throw new RuntimeException('The manual transfer provider receives no callbacks.');
    }

    /**
     * Empty, and honestly so: there is no provider-side ledger to reconcile
     * against. A manual transfer's other side is a bank statement a human reads.
     *
     * @return list<CallbackEvent>
     */
    public function transactionsInWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function supportsRefund(): bool
    {
        return false;
    }
}
