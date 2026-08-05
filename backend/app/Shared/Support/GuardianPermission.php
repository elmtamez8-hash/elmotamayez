<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * What a guardian is allowed to see and be told about, for one student.
 *
 * Lives in Shared rather than in Identity because it is the shared vocabulary of
 * two modules: Identity stores it on the relation, Notifications asks with it.
 * Putting it in either one would make the other reach across a module boundary
 * for a definition (Constitution III).
 *
 * One permission covers both reading and receiving. A guardian who may not see a
 * student's financial record has no business being sent a payment reminder about
 * it either, and splitting the two produced no case where they should differ.
 */
enum GuardianPermission: string
{
    case Attendance = 'attendance';
    case Payments = 'payments';
    case Schedule = 'schedule';
    case Results = 'results';
    case AcademicWarnings = 'academic_warnings';

    public function label(): string
    {
        return match ($this) {
            self::Attendance => 'الحضور والغياب',
            self::Payments => 'المدفوعات والمستحقّات',
            self::Schedule => 'المواعيد والحصص',
            self::Results => 'النتائج والدرجات',
            self::AcademicWarnings => 'الإنذارات الأكاديمية',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
