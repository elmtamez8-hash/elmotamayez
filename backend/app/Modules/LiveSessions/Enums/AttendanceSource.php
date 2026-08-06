<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * Where a mark came from.
 *
 * The spec forbids manual entry as the *primary* source (FR-022), so this is not
 * a preference — it is the field that makes SC-004 checkable: zero rows whose
 * primary source is a person.
 */
enum AttendanceSource: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'آلي',
            self::Manual => 'تحضير يدوي',
        };
    }
}
