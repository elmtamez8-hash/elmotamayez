<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/**
 * The metrics FR-040 names, and nothing else (spec 011 · US6).
 *
 * ⚠️ A COUNT AND A RATIO ARE STORED THE SAME WAY AND READ DIFFERENTLY. Every row
 * carries a numerator and a denominator; `denominator = 0` means «this is a
 * count, not a fraction» and the reader returns the numerator rather than
 * dividing. Storing a percentage instead would make `SC-012`'s zero-difference
 * comparison against the source impossible by construction — rounding is a
 * difference.
 */
enum MetricKey: string
{
    /** Distinct students with a live enrolment, as of the end of the day. */
    case StudentsActive = 'students.active';

    /** Teachers with a profile in the workspace. */
    case TeachersActive = 'teachers.active';

    /**
     * What students owe, in credits, as a POSITIVE number.
     *
     * The balances hold it negative; the sign is flipped once, here, so every
     * reader does not have to remember which way «متأخرات» points.
     */
    case DuesOverdue = 'dues.overdue_credits';

    /** Approved orders over orders raised, in the day's window. */
    case CollectionRate = 'collection.rate';

    /** Enrolments that ended without completing, over all enrolments. */
    case DropoutRate = 'dropout.rate';

    /** Students per region — the one metric whose rows carry a `region_id`. */
    case StudentsByRegion = 'students.by_region';

    public function isRatio(): bool
    {
        return match ($this) {
            self::CollectionRate, self::DropoutRate => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::StudentsActive => 'الطلاب النشطون',
            self::TeachersActive => 'المدرّسون',
            self::DuesOverdue => 'المستحقات المتأخرة (حصص)',
            self::CollectionRate => 'معدّل التحصيل',
            self::DropoutRate => 'نسبة التسرّب',
            self::StudentsByRegion => 'توزيع الطلاب بالمنطقة',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $key): string => $key->value, self::cases());
    }
}
