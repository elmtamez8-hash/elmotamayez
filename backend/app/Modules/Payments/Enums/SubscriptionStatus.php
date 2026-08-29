<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Where a subscription is in its life (data-model §رابعاً).
 *
 * ⚠️ `Cancelled` AND `Expired` ARE NOT ONE STATE WITH A FLAG. Expiry is the
 * clock running out, cancellation is somebody's decision, and only the second
 * has money to reverse behind it — folding them together makes the reversal
 * conditional on a column that means two things, which is how a refund gets
 * issued for a subscription that simply ended.
 *
 * Both are terminal, and `covers()` is the whole of what the access predicate
 * asks: a subscription grants nothing once it leaves `Active`, whichever door it
 * left by.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'سارٍ',
            self::Expired => 'انتهت مدّته',
            self::Cancelled => 'مُلغى',
        };
    }

    /** Whether a subscription in this state opens anything at all. */
    public function covers(): bool
    {
        return $this === self::Active;
    }
}
