<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Modules\Payments\Support\DiscountResolver;

/**
 * How a coupon's `value` is read (spec 011 · FR-014 · D17).
 *
 * Both kinds are supported, and the clamp that makes the fixed one safe lives in
 * {@see DiscountResolver} ALONE — never in the
 * paths that apply a discount. A clamp repeated per path is a rule with as many
 * spellings as there are callers, and the one that gets forgotten is the one
 * where a fixed coupon of 50 on a 30-riyal notebook takes the total below zero.
 */
enum CouponValueKind: string
{
    case Percent = 'percent';
    case FixedMinor = 'fixed_minor';

    /**
     * What this coupon takes off one line, in minor units.
     *
     * ⚠️ THE CLAMP IS HERE AND NOWHERE ELSE — both ends of it. The floor at zero
     * keeps a nonsense stored value from paying the buyer, and the ceiling at the
     * line total is FR-014's «declared minimum», which is zero and is enforced by
     * cutting rather than by a column.
     *
     * ⚠️ AND THE PERCENTAGE FLOORS. `intdiv`, never `round`: rounding a half in
     * the buyer's favour is a decision nobody made, and a floor is the same
     * answer on every engine and in every locale. It also cannot exceed the line,
     * so the clamp below is inert for this arm and stays anyway — the day a
     * percentage above 100 is stored by a seeder running unguarded, it is what
     * stops the total going negative.
     */
    public function discountOn(int $value, int $lineTotalMinor): int
    {
        $line = max(0, $lineTotalMinor);

        $raw = match ($this) {
            self::Percent => intdiv($line * max(0, $value), 100),
            self::FixedMinor => max(0, $value),
        };

        return min($raw, $line);
    }

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'نسبة مئوية',
            self::FixedMinor => 'مبلغ ثابت',
        };
    }
}
