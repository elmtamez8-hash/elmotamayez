<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Shared\Actions\Action;

/**
 * Stop an erasure, by order or obligation (FR-021 · SC-009).
 *
 * ⚠️ THE HOLD HAS THREE DOORS AND THIS ACTION IS ONLY THE FIRST TWO. It stops a
 * request that has not started, and it stops the nightly sweep — but an erasure
 * already WALKING is minutes long, so a check made once when the job started is
 * stale for the rest of it, and erasure does not reverse.
 * {@see ExecuteDataErasure} re-reads the table at the head of every batch; that is
 * the third door, and neither half is sufficient alone.
 *
 * ⚠️ AND THE OPEN REQUEST IS CLAIMED BY A CONDITIONAL UPDATE. A hold placed at the
 * same moment a worker picks the request up is an ordinary race, not an exotic
 * one: `RetryStalledDataRequestsJob` re-dispatches every ten minutes. Reading the
 * status and then writing it lets both through — the hold reports success while
 * the erasure runs. `UPDATE … WHERE status IN (pending, processing)` is the check
 * and the claim in one statement, so the two writes serialise on one row instead
 * of racing.
 */
class PlaceLegalHold extends Action
{
    public function handle(User $subject, User $placedBy, string $reason): LegalHold
    {
        $hold = LegalHold::query()->create([
            'subject_user_id' => $subject->getKey(),
            'reason' => $reason,
            'placed_by_user_id' => $placedBy->getKey(),
            'placed_at' => now(),
        ]);

        /*
        | The hold is written FIRST and the request suspended second, deliberately.
        | The other order leaves a window in which the request is `on_hold` with no
        | hold behind it — a state nothing releases, because releasing reads this
        | table. A hold with no suspended request is merely early.
        */
        DataRequest::query()
            ->where('subject_user_id', $subject->getKey())
            ->whereIn('status', [
                DataRequestStatus::Pending->value,
                DataRequestStatus::Processing->value,
            ])
            ->update([
                'status' => DataRequestStatus::OnHold->value,
                'updated_at' => now(),
            ]);

        return $hold;
    }
}
