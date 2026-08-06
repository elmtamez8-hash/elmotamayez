<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Enums;

/**
 * What this unit was earned for.
 *
 * Stored on the row rather than derived from the settings in force (FR-007ب): a
 * basis read from live configuration answers a different question every time the
 * configuration changes, and the row is a record of a moment that has passed.
 */
enum SettlementBasis: string
{
    case FrozenSeat = 'frozen_seat';
    case ZeroAttendanceCompensation = 'zero_attendance_compensation';

    public function label(): string
    {
        return match ($this) {
            self::FrozenSeat => 'مقعد مُجمَّد',
            self::ZeroAttendanceCompensation => 'تعويض حصة بلا حجوزات',
        };
    }
}
