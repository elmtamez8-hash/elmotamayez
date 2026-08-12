<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Shared\Data\DataTransferObject;

/**
 * What a provider answers when asked to start a charge.
 *
 * Replaces the free `array` ManualTransferProvider returns today: an array
 * contract is a contract nothing checks, and the first gateway adapter would
 * have discovered its keys by reading the manual provider's implementation.
 *
 * ⚠️ `redirectUrl` IS NULLABLE, and that is the whole reason this is not just a
 * URL. A bank transfer has no payment page — it has instructions and a
 * reference. A caller that assumed a redirect would send the student to nothing;
 * a caller reading this type has to decide which of the two it has.
 *
 * ⚠️ `final class` with `readonly` PROPERTIES, never `final readonly class`: the
 * base DataTransferObject is a non-readonly abstract class, and a readonly class
 * may not extend one — that is a fatal error at compile time, not a lint.
 */
final class ChargeIntent extends DataTransferObject
{
    /** @param array<string, mixed> $providerData */
    public function __construct(
        public readonly string $reference,
        public readonly PaymentMethod $method,
        public readonly int $amountMinor,
        public readonly string $currency,
        /** Where to send the payer, when the method has a page at all. */
        public readonly ?string $redirectUrl = null,
        /** What to tell the payer when it does not — an account, a reference. */
        public readonly ?string $instructions = null,
        /**
         * ⚠️ Provider echo only, and NEVER payment instrument data (FR-003 ·
         * NFR-010). A card number that reaches here is stored, logged and
         * exported by everything downstream that treats this as opaque.
         */
        public readonly array $providerData = [],
    ) {}

    /**
     * ⚠️ Declared here because DataTransferObject does NOT provide it — every
     * one of the sixteen DTOs that has it declares its own. `contracts/
     * provider.md` says otherwise and is wrong.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $providerData = $data['provider_data'] ?? [];

        return new self(
            reference: (string) $data['reference'],
            method: $data['method'] instanceof PaymentMethod
                ? $data['method']
                : PaymentMethod::from((string) $data['method']),
            amountMinor: (int) $data['amount_minor'],
            currency: (string) $data['currency'],
            redirectUrl: isset($data['redirect_url']) ? (string) $data['redirect_url'] : null,
            instructions: isset($data['instructions']) ? (string) $data['instructions'] : null,
            providerData: is_array($providerData) ? $providerData : [],
        );
    }
}
