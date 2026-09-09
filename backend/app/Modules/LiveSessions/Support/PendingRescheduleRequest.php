<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;

/**
 * The one statement that settles a reschedule request.
 *
 * ⚠️ SETTLING IS A CONDITIONAL UPDATE ON `status = pending`, never a read
 * followed by a save. A teacher and an assistant can both hold the queue open;
 * written the obvious way both read a pending row, both write, and the lesson is
 * moved twice while the student is told «قُبل» and «رُفض» about one hour.
 *
 * ⚠️ AND `pending_slot` MOVES OFF ITS ZERO INSIDE THE SAME STATEMENT. That is
 * what frees `unique(class_session_id, pending_slot)` the moment the row stops
 * being live — without it a lesson whose postponement was once refused can never
 * be asked about again, and the second ask is rejected as a duplicate of a
 * request that no longer exists.
 *
 * ⚠️ ONLY THE WINNER NOTIFIES. Every caller dispatches its event behind a `true`,
 * so «ولا يُعادُ إخبارُه» needs no second guard anywhere.
 */
final class PendingRescheduleRequest
{
    public static function settle(
        SessionRescheduleRequest $request,
        string $status,
        User $decider,
        ?string $reason = null,
    ): bool {
        return SessionRescheduleRequest::query()
            // The student is a member of no workspace, so the scope is inert for
            // them and would filter by the READER's workspace for anyone else.
            ->withoutWorkspaceScope()
            ->whereKey($request->getKey())
            ->where('status', SessionRescheduleRequest::PENDING)
            ->update([
                'status' => $status,
                'decided_by' => $decider->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
                // The row's own id: unique by construction, so every settled row
                // coexists with every other and only the live ones compete.
                'pending_slot' => $request->getKey(),
            ]) > 0;
    }
}
