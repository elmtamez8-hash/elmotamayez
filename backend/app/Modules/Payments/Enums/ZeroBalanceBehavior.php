<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * What happens when a balance reaches zero (FR-027, Q-4).
 *
 * Configurable per workspace rather than fixed: a teacher whose students pay in
 * advance wants the booking stopped, and one who collects by hand wants the
 * reminder and the booking left alone. Hard-coding either answer means
 * rewriting it at the first teacher whose model differs.
 */
enum ZeroBalanceBehavior: string
{
    case Block = 'block';

    case Remind = 'remind';

    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Block => 'منع الحجز الجديد',
            self::Remind => 'تذكير بالدفع',
            self::Both => 'منع الحجز وتذكير بالدفع',
        };
    }

    public function blocks(): bool
    {
        return $this === self::Block || $this === self::Both;
    }

    public function reminds(): bool
    {
        return $this === self::Remind || $this === self::Both;
    }
}
