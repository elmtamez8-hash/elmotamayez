<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * How far a reported breach has been handled (FR-040).
 *
 * ⚠️ `Contained` AND `Notified` ARE TWO STEPS, NOT ONE. Stopping the leak and
 * telling the people whose data leaked are different obligations on different
 * clocks, and a single "handled" value lets the second be forgotten while the
 * record reads as complete.
 */
enum BreachStatus: string
{
    case Reported = 'reported';
    case Triaged = 'triaged';
    case Contained = 'contained';
    case Notified = 'notified';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'بلاغ جديد',
            self::Triaged => 'قيد الفحص',
            self::Contained => 'احتُوي',
            self::Notified => 'أُبلغت الجهات والمعنيّون',
            self::Closed => 'مغلق',
        };
    }
}
