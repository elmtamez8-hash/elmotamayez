<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Enums;

/**
 * The closed set of things that can move a teacher's balance.
 *
 * Closed on purpose: a ledger whose entry types can be extended at the call site
 * is a ledger whose total means something different depending on who wrote the
 * last row. Corrections are `Reversal` entries, never edits (FR-016).
 */
enum LedgerEntryType: string
{
    case Unit = 'unit';
    case Reversal = 'reversal';
    case Deduction = 'deduction';
    case Bonus = 'bonus';
    case Payout = 'payout';
    case CarryOver = 'carry_over';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'وحدة تدريس',
            self::Reversal => 'قيد عكسي',
            self::Deduction => 'خصم',
            self::Bonus => 'مكافأة',
            self::Payout => 'صرف',
            self::CarryOver => 'مُرحَّل',
        };
    }

    /**
     * Whether an entry of this type must carry a negative amount.
     *
     * Enforced rather than trusted: a payout written as a positive number pays
     * the teacher twice in the totals, and nothing about the row would look
     * wrong.
     */
    public function mustBeNegative(): bool
    {
        return match ($this) {
            self::Reversal, self::Deduction, self::Payout => true,
            default => false,
        };
    }
}
