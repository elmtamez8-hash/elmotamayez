<?php

declare(strict_types=1);

namespace App\Modules\Store\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What a buyer submits: which product, how many, and where to post it.
 *
 * The address is three required fields for a printed item and absent for a
 * digital one — asked BEFORE the money, because FR-007 refuses the purchase
 * rather than taking payment and discovering later that there is nowhere to
 * send the parcel.
 */
final class PurchaseData extends DataTransferObject
{
    public function __construct(
        public readonly string $itemUuid,
        public readonly int $quantity = 1,
        public readonly ?string $recipientName = null,
        public readonly ?string $phone = null,
        public readonly ?string $addressLine = null,
        public readonly ?string $notes = null,
        // Optional and untrusted: the resolver decides what it is worth, and a
        // code that does not apply is a refusal rather than a silent zero.
        public readonly ?string $couponCode = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            itemUuid: (string) $data['item_uuid'],
            quantity: (int) ($data['quantity'] ?? 1),
            recipientName: isset($data['recipient_name']) ? (string) $data['recipient_name'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            addressLine: isset($data['address_line']) ? (string) $data['address_line'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            couponCode: isset($data['coupon_code']) ? (string) $data['coupon_code'] : null,
        );
    }

    public function hasAddress(): bool
    {
        return $this->recipientName !== null && $this->recipientName !== ''
            && $this->phone !== null && $this->phone !== ''
            && $this->addressLine !== null && $this->addressLine !== '';
    }
}
