<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Enums;

/**
 * A settlement period only ever moves forwards.
 *
 * There is no path back to `Open`. A unit that arrives late for a closed period
 * is carried into the next one (FR-024) — reopening would change a total the
 * teacher has already been told, and possibly already been paid.
 */
enum SettlementPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوحة',
            self::Closed => 'مغلقة',
            self::Paid => 'مصروفة',
        };
    }
}
