<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Data\DataTransferObject;

/**
 * One provider notification, parsed into what the platform actually decides on.
 *
 * ⚠️ `amountMinor` IS AN INT, and the comparison it exists for is the reason
 * phase 2ج is a barrier rather than an optimisation: `payment_transactions.
 * amount` is `decimal:2` today and Laravel's cast returns a STRING, so
 * `$event->amountMinor !== $transaction->amount` is true for every payment ever
 * made — including the correct ones. The guard that catches a tampered amount
 * would fire on all of them or, once someone "fixes" it with a float
 * multiplication, on none.
 *
 * ⚠️ `externalId` is the provider's own id for the notification and the
 * idempotency key. A provider that mints a new one per resend defeats it, which
 * is why the unique index is on (provider, external_id) and why FakePaymentProvider
 * carries that case.
 *
 * ⚠️ `final class` with `readonly` PROPERTIES, never `final readonly class` —
 * the base is a non-readonly abstract class and PHP rejects the combination at
 * compile time. Same warning as ChargeIntent, written twice on purpose: the two
 * are parallel tasks and one implementer may never read the other file.
 */
final class CallbackEvent extends DataTransferObject
{
    /** @param array<string, mixed> $safePayload */
    public function __construct(
        public readonly string $provider,
        /** The provider's id for THIS notification — the idempotency key. */
        public readonly string $externalId,
        /** Our reference, as the provider echoes it back. */
        public readonly string $reference,
        public readonly PaymentStatus $status,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $failureReason = null,
        /**
         * ⚠️ SCRUBBED AT THE CONTRACT BOUNDARY, not later. Whatever survives
         * here is stored and shown; a provider that echoes a PAN into its
         * payload must lose it in parseCallback(), because every consumer
         * downstream treats this as opaque and none of them will look.
         */
        public readonly array $safePayload = [],
    ) {}

    /**
     * ⚠️ Declared here because DataTransferObject does NOT provide it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $safePayload = $data['safe_payload'] ?? [];

        return new self(
            provider: (string) $data['provider'],
            externalId: (string) $data['external_id'],
            reference: (string) $data['reference'],
            status: $data['status'] instanceof PaymentStatus
                ? $data['status']
                : PaymentStatus::from((string) $data['status']),
            amountMinor: (int) $data['amount_minor'],
            currency: (string) $data['currency'],
            failureReason: isset($data['failure_reason']) ? (string) $data['failure_reason'] : null,
            safePayload: is_array($safePayload) ? $safePayload : [],
        );
    }
}
