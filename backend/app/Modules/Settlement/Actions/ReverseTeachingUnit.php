<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

/**
 * Corrects a unit by writing a new one, never by touching the old.
 *
 * The original stays exactly as it was, so "what did we believe in March" is
 * still answerable in June (FR-006 · SC-013). The correction carries a negative
 * amount, its author and its reason — the three things an argument about pay
 * needs and that an UPDATE would have destroyed.
 */
class ReverseTeachingUnit extends Action
{
    use LogsActivity;

    public function handle(TeachingUnit $original, string $reason, ?User $by = null): ?TeachingUnit
    {
        if ($original->isReversal() || $original->status === TeachingUnitStatus::Reversed) {
            return null;
        }

        $reversal = TeachingUnit::query()->create([
            'workspace_id' => (int) $original->workspace_id,
            'student_user_id' => (int) $original->student_user_id,
            'teacher_profile_id' => (int) $original->teacher_profile_id,
            'class_session_id' => (int) $original->class_session_id,
            'session_type' => $original->session_type,
            'settlement_rate_id' => $original->settlement_rate_id,
            'amount_minor' => -$original->amount_minor,
            'currency' => (string) $original->currency,
            'frozen_seats' => $original->frozen_seats,
            'basis' => $original->basis,
            'status' => TeachingUnitStatus::Reversed,
            'delivered_at' => $original->delivered_at,
            'accrued_at' => now(),
            'reversal_of_id' => (int) $original->getKey(),
            'reversal_reason' => $reason,
            'reversed_by' => $by?->getKey(),
        ]);

        $this->logActivity('settlement.unit.reversed', $reversal, [
            'amount_minor' => $reversal->amount_minor,
            'reason' => $reason,
        ]);

        TeachingUnitAccrued::dispatch($reversal);

        return $reversal;
    }
}
