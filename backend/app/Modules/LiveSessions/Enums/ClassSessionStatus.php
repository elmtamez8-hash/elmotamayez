<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * Where a session is in its life.
 *
 * `Completed` does NOT mean "delivered". Delivery is a separate fact — the
 * teacher showed up and stayed — recorded in `delivered_at` and announced by its
 * own event. A session nobody taught still ends (research §R7).
 */
enum ClassSessionStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Interrupted = 'interrupted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'مجدولة',
            self::Live => 'جارية',
            self::Interrupted => 'انقطعت',
            self::Completed => 'منتهية',
            self::Cancelled => 'ملغاة',
            self::Suspended => 'معلّقة بتجميد',
        };
    }

    /** Nothing more will happen to a session in this state. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Whether this session may still be booked into.
     *
     * Suspended is excluded as firmly as cancelled: a freeze that still took
     * bookings would hand students a seat in a session that will not happen.
     */
    public function acceptsBookings(): bool
    {
        return $this === self::Scheduled;
    }

    /**
     * Whether this session counts towards the teacher's completion figures.
     *
     * Cancelled, suspended and interrupted sessions are excluded (FR-026): a
     * teacher must not be marked down for a holiday, nor up for a session that
     * fell over.
     */
    public function countsTowardsCounters(): bool
    {
        return $this === self::Completed;
    }
}
