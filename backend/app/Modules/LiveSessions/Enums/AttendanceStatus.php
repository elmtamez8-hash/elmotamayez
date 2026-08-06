<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * A closed list of four (FR-048).
 *
 * What used to be called "partial attendance" is `Late`. `Excused` is never
 * awarded automatically (FR-049): it is a decision by a person, with a reason,
 * and making it derivable would turn every network drop into an excuse.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'حاضر',
            self::Absent => 'غائب',
            self::Late => 'متأخّر',
            self::Excused => 'غياب بعذر',
        };
    }

    /** Whether the ladder may produce this status on its own. */
    public function isAutomatable(): bool
    {
        return $this !== self::Excused;
    }
}
