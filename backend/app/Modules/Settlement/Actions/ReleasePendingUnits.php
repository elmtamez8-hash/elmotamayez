<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\PackageCompletion;
use App\Shared\Actions\Action;

/**
 * Turns a waiting unit into an earning, the moment its package is complete.
 *
 * Automatic and never a human decision (FR-008ب). A review step here would mean
 * a teacher's pay depends on somebody remembering to look, and the thing being
 * checked — did the recording arrive — is a fact the system already holds.
 */
class ReleasePendingUnits extends Action
{
    public function __construct(
        private readonly PackageCompletion $package,
    ) {}

    /** @return int how many units were released */
    public function handle(ClassSession $session): int
    {
        $missing = $this->package->missingReason($session);

        if ($missing !== null) {
            // Still waiting — but keep the reason current, because it is what the
            // teacher reads when they ask why an hour they taught is not counted
            // (FR-008د).
            TeachingUnit::query()
                ->where('class_session_id', $session->getKey())
                ->where('status', TeachingUnitStatus::PendingPackage)
                ->update(['pending_reason' => $missing]);

            return 0;
        }

        $units = TeachingUnit::query()
            ->where('class_session_id', $session->getKey())
            ->where('status', TeachingUnitStatus::PendingPackage)
            ->get();

        $fault = $this->package->isRecordingFault($session);

        foreach ($units as $unit) {
            $unit->forceFill([
                'status' => TeachingUnitStatus::Accrued,
                'pending_reason' => null,
                // Released even though the recording never arrived. Not the
                // teacher's fault, so not the teacher's loss (FR-008ج) — recorded
                // rather than hidden, because a provider failing often is
                // something somebody should be able to count.
                'recording_fault' => $fault,
                'accrued_at' => now(),
            ])->save();

            TeachingUnitAccrued::dispatch($unit);
        }

        return $units->count();
    }
}
