<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Shared\Actions\Action;
use DomainException;

/**
 * The only way anything enters the ledger.
 *
 * The sign rule is enforced here rather than left to each caller: a payout
 * written as a positive number pays the teacher twice in every total that reads
 * the ledger, and nothing about the row itself would look wrong.
 */
class WriteLedgerEntry extends Action
{
    public function handle(
        int $workspaceId,
        int $teacherProfileId,
        LedgerEntryType $type,
        int $amountMinor,
        string $currency,
        ?int $teachingUnitId = null,
        ?int $settlementPeriodId = null,
        ?int $payoutId = null,
        ?string $reason = null,
        ?int $createdBy = null,
    ): LedgerEntry {
        if ($type->mustBeNegative() && $amountMinor > 0) {
            throw new DomainException('قيد من هذا النوع يجب أن يكون بالسالب.');
        }

        if (! $type->mustBeNegative() && $amountMinor < 0) {
            throw new DomainException('قيد من هذا النوع يجب أن يكون بالموجب.');
        }

        return LedgerEntry::query()->create([
            'workspace_id' => $workspaceId,
            'teacher_profile_id' => $teacherProfileId,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'teaching_unit_id' => $teachingUnitId,
            'settlement_period_id' => $settlementPeriodId,
            'payout_id' => $payoutId,
            'reason' => $reason,
            'created_by' => $createdBy,
        ]);
    }
}
