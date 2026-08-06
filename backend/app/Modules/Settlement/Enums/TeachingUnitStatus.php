<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Enums;

/**
 * Where a teaching unit is between "the session happened" and "the teacher was paid".
 *
 * `PendingPackage` is not a suspicion of the teacher. The seat earns because the
 * absent student still receives the recording, the files and the homework — so
 * until that package exists, the premise of the earning has not been met (Q2ج).
 * It is released automatically, never by a human decision (FR-008ب).
 */
enum TeachingUnitStatus: string
{
    case PendingPackage = 'pending_package';
    case Accrued = 'accrued';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::PendingPackage => 'بانتظار اكتمال الحزمة',
            self::Accrued => 'مستحقّة',
            self::Disputed => 'متنازع عليها',
            self::Settled => 'مسوّاة',
            self::Reversed => 'عكسية',
        };
    }

    /**
     * Whether this unit may be swept into a settlement period.
     *
     * A disputed unit is deliberately excluded (FR-008): closing a period over
     * it would pay a claim nobody has resolved, and reopening a closed period to
     * take it back is forbidden.
     */
    public function isSettleable(): bool
    {
        return $this === self::Accrued;
    }

    /** Whether this unit's amount belongs in the teacher's running total. */
    public function countsTowardsTotal(): bool
    {
        return match ($this) {
            self::Accrued, self::Settled, self::Reversed => true,
            default => false,
        };
    }
}
