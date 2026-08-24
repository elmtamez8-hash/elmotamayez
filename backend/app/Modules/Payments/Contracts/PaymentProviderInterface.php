<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Data\ChargeIntent;
use App\Modules\Payments\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Abstraction over a payment provider (manual transfer, a Qatari gateway, ...).
 *
 * New providers implement this interface and are tagged 'payment.providers' in
 * their module provider. Business logic depends on this interface and never on a
 * concrete provider — ProviderExtensibilityTest fails the build if adding one
 * requires touching an Action or a Model.
 *
 * ⚠️ THERE IS NO refund() HERE, AND ITS ABSENCE IS THE POLICY (research §د3).
 * Every refund on this platform is issued as CREDITS through AdjustCredits;
 * there is no cash path and no provider-side refund call. A method declared
 * here would be the door, and the guard would then have to be a rule someone
 * remembers rather than an interface nobody can call.
 */
interface PaymentProviderInterface
{
    /**
     * The provider's identifier (e.g. 'manual', 'paymob'). Registry key.
     */
    public function identifier(): string;

    /**
     * Start a charge for the given order.
     *
     * ⚠️ `$returnUrl` IS REQUIRED AND HAS NO DEFAULT, DELIBERATELY. It is where
     * the gateway sends the payer back to when it is done with them — the other
     * direction from `ChargeIntent::redirectUrl`, which sends them TO the
     * gateway. Spec 007 shipped the return SCREEN and no way to reach it: no
     * field here, no config, no builder, so a gateway author would have found a
     * finished page with no inbound path and invented a URL nobody registered.
     *
     * A parameter with a default would have been the polite change and would
     * have kept exactly that silence. Without one, every implementor — including
     * tomorrow's — has to look at it once.
     *
     * A provider with no payment page ignores it, and `ManualTransferProvider`
     * says so where it does.
     */
    public function createCharge(Order $order, string $returnUrl): ChargeIntent;

    /**
     * Verify a charge status with the provider (for gateway/webhook flows).
     *
     * @param  array<string, mixed>  $reference
     * @return array{status: string, verified: bool}
     */
    public function verify(array $reference): array;

    /**
     * Whether a callback really came from this provider.
     *
     * ⚠️ Takes the RAW body, not the parsed array: a signature covers the exact
     * bytes that were signed, and json_decode + re-encode is not those bytes.
     * The comparison inside must be hash_equals(), never `===` — a
     * short-circuiting compare leaks the answer through its own timing.
     *
     * @param  array<string, string>  $headers
     */
    public function verifySignature(string $rawBody, array $headers): bool;

    /**
     * Parse a verified callback into the fields the platform decides on.
     *
     * ⚠️ This is where payment instrument data is dropped (FR-003 · NFR-010).
     * Everything downstream treats CallbackEvent::$safePayload as opaque.
     */
    public function parseCallback(string $rawBody): CallbackEvent;

    /**
     * What the provider says it processed in a window — the OTHER side of
     * reconciliation (FR-016).
     *
     * A comparison against our own rows alone proves almost nothing: both sides
     * are written by the same code path in the same transaction, so a payment
     * that never reached us is invisible in perfect agreement. This is the only
     * input that comes from outside.
     *
     * @return list<CallbackEvent>
     */
    public function transactionsInWindow(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * Whether this provider supports automated refunds.
     *
     * ⚠️ Kept as a CAPABILITY QUESTION with no method behind it. Refunds are
     * credits (research §د3); this tells an operator what a provider could do,
     * and nothing in the platform acts on a `true`.
     */
    public function supportsRefund(): bool;
}
