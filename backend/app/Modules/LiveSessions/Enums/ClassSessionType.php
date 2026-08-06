<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * One-to-one or group.
 *
 * Declared, never inferred from the seat count (FR-001أ). A group session that
 * happens to have one booking is still a group session, and pricing (006) and
 * teacher payout (014) differ by type in kind, not by degree — reading the type
 * off `seats_total` at query time would silently reprice it.
 */
enum ClassSessionType: string
{
    case Individual = 'individual';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'فردية',
            self::Group => 'جماعية',
        };
    }

    /** The seat count a session of this type must be created with. */
    public function allowsSeats(int $seats): bool
    {
        return match ($this) {
            self::Individual => $seats === 1,
            self::Group => $seats > 1,
        };
    }
}
