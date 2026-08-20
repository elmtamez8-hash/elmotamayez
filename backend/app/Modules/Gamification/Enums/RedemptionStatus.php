<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Enums;

/**
 * Where a redemption request stands.
 *
 * ⚠️ THE MOVE OUT OF Pending IS AN ATOMIC CONDITIONAL UPDATE, never a read
 * followed by a save. Two clicks on "reject" that both read `pending` refund the
 * coins TWICE — coins created out of nothing, which is the side FR-034 does not
 * guard because it is written about balances going negative.
 */
enum RedemptionStatus: string
{
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';

    public function labelAr(): string
    {
        return match ($this) {
            self::Pending => 'قيد التنفيذ',
            self::Fulfilled => 'مُنفَّذ',
            self::Rejected => 'مرفوض',
        };
    }
}
