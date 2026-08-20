<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Enums;

/**
 * What a shop item actually is.
 */
enum RewardType: string
{
    case Discount = 'discount';
    case Printed = 'printed';
    case DeadlineExtension = 'deadline_extension';
    case StreakShield = 'streak_shield';

    /**
     * Whether the reward costs the teacher real money.
     *
     * These MUST carry a monthly cap (FR-031 · SC-012): without one, gamification
     * stops being an engagement budget and becomes an open marketing expense — the
     * fourth of the four design controls the source document says cannot be
     * skipped. Enforced in the Action, not in the FormRequest alone: the seeder
     * and the panel reach the same write with no form behind them.
     */
    public function isMoneyValued(): bool
    {
        return $this === self::Discount || $this === self::Printed;
    }

    /** The Arabic label. Every surface in this product is Arabic-only. */
    public function labelAr(): string
    {
        return match ($this) {
            self::Discount => 'خصم على حصة',
            self::Printed => 'نسخة مطبوعة',
            self::DeadlineExtension => 'تأجيل تسليم واجب',
            self::StreakShield => 'درع حماية السلسلة',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
