<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The closed list of ledger entry types (FR-003).
 *
 * Closed on purpose: the displayed balance is asserted to equal the sum of its
 * entries (FR-004, SC-001), and that assertion is only checkable if every way a
 * balance can move is one of these. A free-text "type" column is a hole in it.
 *
 * The sign lives on `credits`, not here. Deriving it from the type would make
 * `adjustment` — the one type that must be able to go either way — undecidable.
 */
enum CreditTransactionType: string
{
    case Purchase = 'purchase';

    case Consume = 'consume';

    case Bonus = 'bonus';

    case Refund = 'refund';

    case Adjustment = 'adjustment';

    /** Built and wired from day one, switched off by default — see Q-5. */
    case Expire = 'expire';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'شراء',
            self::Consume => 'استهلاك',
            self::Bonus => 'رصيد ترويجي',
            self::Refund => 'استرداد',
            self::Adjustment => 'تسوية',
            self::Expire => 'انتهاء صلاحية',
        };
    }

    /**
     * Types that add credits, and therefore open a lot for consumption.
     *
     * @return list<self>
     */
    public static function lotOpening(): array
    {
        return [self::Purchase, self::Bonus];
    }
}
