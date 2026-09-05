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

    /*
    | A seat a duration package already paid for (027 · FR-048).
    |
    | ⚠️ ITS AMOUNT IS ZERO, AND THAT IS THE REQUIREMENT RATHER THAN AN OMISSION.
    | A frozen seat is priced per lesson because the student bought a lesson;
    | a subscriber bought a MONTH, so pricing their seat per lesson makes what the
    | platform pays grow with the timetable while what it collects stays fixed —
    | twenty subscribers over thirteen lessons is two hundred and sixty payments
    | against twenty packages. The row is still written, at zero, because the
    | statement has to SHOW the lessons that were taught: a seat that produced no
    | row at all is an obligation nobody can see.
    */
    case SubscriptionSeat = 'subscription_seat';

    public function label(): string
    {
        return match ($this) {
            self::FrozenSeat => 'مقعد مُجمَّد',
            self::ZeroAttendanceCompensation => 'تعويض حصة بلا حجوزات',
            self::SubscriptionSeat => 'مقعد باشتراك',
        };
    }
}
