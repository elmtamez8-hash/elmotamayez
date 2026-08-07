<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Support\SettlementSettings;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * Something is taken off the teacher's total, and the reason travels with it.
 *
 * The reason is required, not optional (FR-025). "We took 250 off" with nothing
 * after it is the message that becomes a support ticket, and by the time anyone
 * asks, the person who did it has forgotten. The statement renders it beside the
 * amount for exactly that reason.
 *
 * Unstamped, so it lands in the open window and is claimed by the next close.
 * A deduction has no teaching unit behind it — which is precisely why the
 * statement's totals read the LEDGER and not `teaching_units`.
 */
class RecordDeduction extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly WriteLedgerEntry $ledger,
        private readonly SettlementSettings $settings,
    ) {}

    public function handle(
        TeacherProfile $teacher,
        int $amountMinor,
        string $reason,
        User $by,
    ): LedgerEntry {
        if ($amountMinor <= 0) {
            throw new DomainException('مبلغ الخصم يجب أن يكون أكبر من صفر.');
        }

        if (trim($reason) === '') {
            throw new DomainException('الخصم يحتاج سبباً.');
        }

        $entry = $this->ledger->handle(
            workspaceId: (int) $teacher->workspace_id,
            teacherProfileId: (int) $teacher->getKey(),
            type: LedgerEntryType::Deduction,
            // Taken as a positive and stored negative: a caller who passes 250
            // means "take 250 off", and asking every call site to remember the
            // sign is asking for the one that forgets.
            amountMinor: -$amountMinor,
            currency: $this->settings->currency(),
            reason: trim($reason),
            createdBy: $by->getKey(),
        );

        // The ledger row already carries the reason and the author, so this is
        // not a second copy of the record — it is the record in the ONE place an
        // auditor reads every administrative act in one list, next to the close
        // and the payout that surround it.
        $this->logActivity('settlement.deduction.recorded', $entry, [
            'amount_minor' => $entry->amount_minor,
            'reason' => $entry->reason,
        ]);

        return $entry;
    }
}
