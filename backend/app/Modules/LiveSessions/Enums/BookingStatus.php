<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * What happened to a seat.
 *
 * `Released` is deliberately distinct from either cancellation: the student did
 * nothing — their eligibility lapsed and the system took the seat back (FR-012).
 * Filing that under "cancelled" would put a mark against someone who never
 * cancelled anything.
 */
enum BookingStatus: string
{
    case Booked = 'booked';
    case CancelledInWindow = 'cancelled_in_window';
    case CancelledLate = 'cancelled_late';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'محجوز',
            self::CancelledInWindow => 'ملغى ضمن المهلة',
            self::CancelledLate => 'ملغى بعد المهلة',
            self::Released => 'مقعد محرَّر',
        };
    }

    /**
     * Whether this seat is still charged for.
     *
     * A late cancellation keeps its seat billable (FR-010) — that is the whole
     * point of the deadline. It is also why `billable_seats` is frozen at the
     * deadline rather than recomputed later (FR-060).
     */
    public function isBillable(): bool
    {
        return match ($this) {
            self::Booked, self::CancelledLate => true,
            self::CancelledInWindow, self::Released => false,
        };
    }

    /** Whether this seat still occupies capacity. */
    public function occupiesSeat(): bool
    {
        return $this === self::Booked;
    }
}
