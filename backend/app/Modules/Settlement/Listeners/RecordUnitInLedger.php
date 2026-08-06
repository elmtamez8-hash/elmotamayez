<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Modules\Settlement\Actions\WriteLedgerEntry;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Events\TeachingUnitAccrued;

/**
 * A unit becomes an earning; the ledger gets the line.
 *
 * Split from the accrual itself so the ledger has exactly one writer regardless
 * of which route produced the unit — delivery, a late release, or a correction.
 * Three call sites writing their own entries is three chances for the balance to
 * stop meaning the sum of its rows.
 */
class RecordUnitInLedger
{
    public function __construct(
        private readonly WriteLedgerEntry $ledger,
    ) {}

    public function handle(TeachingUnitAccrued $event): void
    {
        $unit = $event->unit;

        // A unit priced at nothing — no rate was in force when the session ran —
        // is already flagged for review. Writing a zero line would add noise to
        // the ledger without adding information.
        if ($unit->amount_minor === 0) {
            return;
        }

        $this->ledger->handle(
            workspaceId: (int) $unit->workspace_id,
            teacherProfileId: (int) $unit->teacher_profile_id,
            type: $unit->amount_minor < 0 ? LedgerEntryType::Reversal : LedgerEntryType::Unit,
            amountMinor: $unit->amount_minor,
            currency: (string) $unit->currency,
            teachingUnitId: (int) $unit->getKey(),
        );
    }
}
