<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Shared\Actions\Action;

/**
 * Lift a hold, and let whatever it suspended proceed (FR-021).
 *
 * ⚠️ THE SUSPENDED REQUEST IS NOT RESUMED AUTOMATICALLY, AND THAT IS THE DECISION.
 * A hold is placed because somebody decided the data must stay; lifting it says
 * the reason has gone, not that the erasure should now run unattended. The request
 * returns to `pending`, where the officer's queue shows it and a person executes
 * it — the same shape the request took before the hold, and the reason
 * `executed_by_user_id` exists at all (FR-026).
 *
 * ⚠️ AND IT ONLY RETURNS A REQUEST TO `pending` WHEN NO OTHER HOLD REMAINS. Two
 * courts, two holds, one release — resuming on the first would proceed against a
 * hold still in force, which is the one mistake in this file that cannot be undone.
 */
class ReleaseLegalHold extends Action
{
    public function handle(LegalHold $hold, User $releasedBy): LegalHold
    {
        /*
        | ⚠️ A CONDITIONAL UPDATE, so two officers pressing release together
        | release once. `released_at` is the whole state — there is no `is_active`
        | boolean beside it, because two answers to one question diverge at the
        | first write that touches one of them, and here the divergence means an
        | erasure proceeding against a hold a court placed.
        */
        $released = LegalHold::query()
            ->whereKey($hold->getKey())
            ->whereNull('released_at')
            ->update([
                'released_at' => now(),
                'released_by_user_id' => $releasedBy->getKey(),
                'updated_at' => now(),
            ]);

        $hold->refresh();

        if ($released === 0 || LegalHold::heldFor((int) $hold->subject_user_id)) {
            return $hold;
        }

        /*
        | Queried directly rather than through a relation on `User`. `data_requests`
        | is `Compliance`'s table, and hanging a relation for it off the shared
        | `User` model would put this module's schema in a class every other module
        | uses — Constitution III, and the reason the export walk is a tag rather
        | than thirteen relations. No `withoutWorkspaceScope()` is needed: the table
        | is platform-owned and carries no tenant column at all.
        */
        DataRequest::query()
            ->where('subject_user_id', $hold->subject_user_id)
            ->where('status', DataRequestStatus::OnHold->value)
            ->update([
                'status' => DataRequestStatus::Pending->value,
                /*
                | ⚠️ THE INTERRUPTED AUTHORISATION IS SPENT, AND LEAVING IT SET IS A
                | REQUEST NOBODY CAN EVER RUN AGAIN. The officer's execute claims
                | with `WHERE status = pending AND executed_by_user_id IS NULL`, so
                | a request that was executed, met a hold mid-walk and came back
                | here would answer 409 to every later press — while `store` never
                | dispatches an erasure and the sweep never reads `pending`. Dead in
                | three directions at once.
                |
                | Clearing it is also what FR-026 means: each RUN is answered for by
                | the person who ordered it, and a walk stopped by a court order was
                | not the run that finished. Nothing is lost on the concurrency side
                | — two officers pressing together are already serialised by the
                | job's own `pending → processing` claim, not by this column.
                */
                'executed_by_user_id' => null,
                'refusal_reason' => null,
                'updated_at' => now(),
            ]);

        return $hold;
    }
}
