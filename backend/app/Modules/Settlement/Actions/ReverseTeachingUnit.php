<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Database\UniqueConstraintViolationException;

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

        /*
        | ⚠️ A SECOND PRESS IS AN ANSWER, NOT A 500. The original is never touched
        | (FR-006), so neither guard above can see that it was already corrected —
        | its status stays `accrued`. What sees it is the reversal row itself, by
        | the ORIGINAL's key, and without the workspace scope: the officer's
        | fallback workspace is not the unit's, and a scoped `exists()` would
        | answer «not yet» and walk straight into the unique index.
        |
        | The catch is the race the read cannot close — two presses in the same
        | instant. `teaching_units_seat_unique` refuses the second insert, and that
        | refusal means exactly what the read would have said.
        */
        $alreadyReversed = TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('reversal_of_id', $original->getKey())
            ->exists();

        if ($alreadyReversed) {
            return null;
        }

        try {
            $reversal = $this->createReversal($original, $reason, $by);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->logActivity('settlement.unit.reversed', $reversal, [
            'amount_minor' => $reversal->amount_minor,
            'reason' => $reason,
        ]);

        TeachingUnitAccrued::dispatch($reversal);

        return $reversal;
    }

    private function createReversal(TeachingUnit $original, string $reason, ?User $by): TeachingUnit
    {
        return TeachingUnit::query()->create([
            'workspace_id' => (int) $original->workspace_id,
            'student_user_id' => (int) $original->student_user_id,
            'teacher_profile_id' => (int) $original->teacher_profile_id,
            'class_session_id' => (int) $original->class_session_id,
            'session_type' => $original->session_type,
            'settlement_rate_id' => $original->settlement_rate_id,
            'amount_minor' => -$original->amount_minor,
            'currency' => (string) $original->currency,
            'frozen_seats' => $original->frozen_seats,
            // Copied with the rest of the frozen facts. Omitted, a reversal row
            // reads as «not judged» — the pre-035 era — on a statement beside the
            // row it reverses, which does carry them.
            'attended_seats' => $original->attended_seats,
            'charged_seats' => $original->charged_seats,
            'basis' => $original->basis,
            'status' => TeachingUnitStatus::Reversed,
            'delivered_at' => $original->delivered_at,
            'accrued_at' => now(),
            'reversal_of_id' => (int) $original->getKey(),
            'reversal_reason' => $reason,
            'reversed_by' => $by?->getKey(),
        ]);
    }
}
