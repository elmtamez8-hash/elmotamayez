<?php

declare(strict_types=1);

namespace App\Shared\Data;

use App\Shared\Contracts\SettlementClearance;

/**
 * What is outstanding in both directions, in minor units (spec 013 · FR-032).
 *
 * ⚠️ TWO NUMBERS, NOT A NET. A teacher owed 500 and owing 500 is not the same
 * situation as a teacher who owes and is owed nothing, and offboarding must stop
 * for the first — a net of zero would let both debts leave with them.
 *
 * ⚠️ AND SIGNED INTEGERS IN MINOR UNITS, matching 014 deliberately: `decimal:2`
 * casts to a STRING in Laravel, so every sum over it goes through a float.
 *
 * @see SettlementClearance
 */
final class SettlementStanding extends DataTransferObject
{
    public function __construct(
        public readonly int $owedToTeacherMinor = 0,
        public readonly int $owedByTeacherMinor = 0,
    ) {}

    public function isCleared(): bool
    {
        return $this->owedToTeacherMinor === 0 && $this->owedByTeacherMinor === 0;
    }
}
