<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Models\Coupon;
use App\Shared\Data\DataTransferObject;

/**
 * ONE discount and where it came from (spec 011 · FR-014 · D13).
 *
 * ⚠️ SINGULAR BY CONSTRUCTION. «The highest one alone applies, there is no
 * stacking» is the declared policy, and a DTO carrying a list would let the
 * policy be re-decided by whoever sums it next — in a different file, months
 * later, without noticing they were deciding anything.
 *
 * That singularity is also what makes SC-005 («no amount falls below the
 * declared minimum») true by construction for a percentage: one discount of at
 * most 100% cannot take a line below zero. It is NOT enough for a fixed amount —
 * a 50 coupon on a 30-riyal notebook — which is why the clamp lives in
 * {@see CouponValueKind::discountOn()} and the
 * declared minimum is zero, enforced by cutting rather than by a column.
 */
final class AppliedDiscount extends DataTransferObject
{
    private function __construct(
        public readonly int $amountMinor,
        public readonly string $source,
        public readonly ?Coupon $coupon = null,
        public readonly ?string $label = null,
    ) {}

    public static function none(): self
    {
        return new self(0, 'none');
    }

    public static function coupon(Coupon $coupon, int $amountMinor): self
    {
        return new self($amountMinor, 'coupon', $coupon, 'كوبون '.$coupon->code);
    }

    public static function sibling(int $amountMinor, int $percent): self
    {
        return new self($amountMinor, 'sibling', null, sprintf('خصم الإخوة %d%%', $percent));
    }

    public function isNothing(): bool
    {
        return $this->amountMinor <= 0;
    }

    /**
     * ⚠️ THE COUPON IS DELIBERATELY ABSENT FROM THE PAYLOAD SHAPE. A buyer is
     * told what came off and why; the coupon's ceiling, its remaining count and
     * its scope are the platform's, and a preview that returned them would be a
     * free enumeration tool on the one endpoint that exists to be guessed at.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'discount_minor' => $this->amountMinor,
            'source' => $this->source,
            'label' => $this->label,
        ];
    }
}
