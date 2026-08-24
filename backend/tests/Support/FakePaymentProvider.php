<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Data\CallbackEvent;
use App\Modules\Payments\Data\ChargeIntent;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use Carbon\CarbonImmutable;

/**
 * A gateway that does whatever the test needs, in memory.
 *
 * NFR-009 forbids a network call in the suite, and Q8 defers the real adapter —
 * so this is the only gateway that exists. It carries SEVEN behaviours because
 * each one is a requirement nothing else can reach:
 *
 *   1. success                  — the happy path
 *   2. failure                  — FR-008, a reason the student can read
 *   3. duplicate callback       — the same external_id twice (idempotency)
 *   4. invalid signature        — NFR-011, the forgery is refused
 *   5. success, NO callback     — the ONLY way into SC-004: reconciliation is
 *                                 what notices, and a provider that always
 *                                 calls back can never produce the case
 *   6. valid signature, WRONG amount — the tamper the signature does not catch,
 *                                 because it is our own record that disagrees
 *   7. new external_id per resend    — defeats idempotency by design, which is
 *                                 what makes the reference the real key
 *
 * ⚠️ In tests/, not app/. A fake in app/ is a provider a deployment can resolve.
 */
class FakePaymentProvider implements PaymentProviderInterface
{
    /** Overridden by a test that registers a second provider (NFR-003). */
    public string $identifier = 'fake';

    /** What the next callback reports. */
    public PaymentStatus $outcome = PaymentStatus::Captured;

    public ?string $failureReason = null;

    /** False makes every callback a forgery (case 4). */
    public bool $signatureValid = true;

    /** Case 5: the payment succeeds at the provider and nothing calls back. */
    public bool $sendsCallback = true;

    /** Case 6: what the provider claims, when it is not what we asked for. */
    public ?int $reportedAmountMinor = null;

    /** Case 7: a fresh external id on every parse, defeating idempotency. */
    public bool $freshExternalIdPerResend = false;

    /** Case 3: the id every callback carries unless case 7 is on. */
    public string $externalId = 'evt_1';

    /** Every charge this provider was asked to start, in order. */
    public int $chargeCount = 0;

    /**
     * The return URL handed to each charge, in order.
     *
     * @var list<string>
     */
    public array $returnUrls = [];

    /** What transactionsInWindow() reports — reconciliation's other side. */
    public array $windowTransactions = [];

    private int $resendCounter = 0;

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function createCharge(Order $order, string $returnUrl): ChargeIntent
    {
        $this->chargeCount++;

        // ⚠️ CAPTURED, NOT IGNORED. This is the only place a test can see what
        // the platform actually hands a gateway — and the return URL was the
        // one field spec 007 never built, so a fake that swallowed it would let
        // that gap reappear silently.
        $this->returnUrls[] = $returnUrl;

        return new ChargeIntent(
            reference: 'FAKE-'.$order->getKey().'-'.$this->chargeCount,
            method: PaymentMethod::Gateway,
            amountMinor: $order->amount_minor,
            currency: $order->currency,
            redirectUrl: 'https://fake.test/pay/'.$order->uuid,
        );
    }

    /** @param array<string, mixed> $reference */
    public function verify(array $reference): array
    {
        return [
            'status' => $this->outcome->value,
            'verified' => $this->outcome === PaymentStatus::Captured,
        ];
    }

    /** @param array<string, string> $headers */
    public function verifySignature(string $rawBody, array $headers): bool
    {
        return $this->signatureValid;
    }

    public function parseCallback(string $rawBody): CallbackEvent
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true) ?: [];

        if ($this->freshExternalIdPerResend) {
            $this->resendCounter++;
        }

        return new CallbackEvent(
            provider: $this->identifier,
            externalId: $this->freshExternalIdPerResend
                ? $this->externalId.'_'.$this->resendCounter
                : $this->externalId,
            reference: (string) ($body['reference'] ?? ''),
            status: $this->outcome,
            amountMinor: $this->reportedAmountMinor ?? (int) ($body['amount_minor'] ?? 0),
            currency: (string) ($body['currency'] ?? 'QAR'),
            failureReason: $this->failureReason,
            // Deliberately narrow: whatever a real provider echoes, only these
            // two fields ever leave the contract boundary.
            safePayload: ['reference' => $body['reference'] ?? null],
        );
    }

    /** @return list<CallbackEvent> */
    public function transactionsInWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->windowTransactions;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    /**
     * The body a real gateway would POST. Built here so a test states the CASE
     * it is exercising, not the JSON.
     */
    public function callbackBody(string $reference, int $amountMinor, string $currency = 'QAR'): string
    {
        return (string) json_encode([
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
        ]);
    }
}
