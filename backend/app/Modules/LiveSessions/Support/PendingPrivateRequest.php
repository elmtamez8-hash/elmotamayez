<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use Tests\Feature\LiveSessions\PrivateSessionRequestUniquenessTest;

/**
 * The one statement that settles a private-session request.
 *
 * ⚠️ SETTLING IS A CONDITIONAL UPDATE ON `status = pending`, never a read
 * followed by a save. Four different things end a request — the teacher accepts,
 * the teacher refuses, the student withdraws, the sweep times it out — and any
 * two of them can arrive at once. Written the obvious way, two of them both read
 * a pending row, both write, and the student is told «قُبل» and «انتهت المهلة»
 * about the same request, with a session created by the decision that was
 * supposed to have lost. The statement is both the check and the claim.
 *
 * ⚠️ AND `pending_slot` MOVES OFF ITS ZERO INSIDE THE SAME STATEMENT. That is
 * what frees `unique(student_user_id, starts_at, pending_slot)` the moment the
 * row stops being live — without it a Tuesday six o'clock that a teacher once
 * refused is booked against that student for ever, and the second ask is
 * rejected as a duplicate of a request that no longer exists. The behaviour is
 * measured in {@see PrivateSessionRequestUniquenessTest},
 * whose third case is the load-bearing one: the first two pass against a build
 * that never releases the slot at all.
 *
 * ⚠️ ONLY THE WINNER NOTIFIES. Every caller here dispatches its event behind a
 * `true`, so «ولا يُعادُ إخبارُه» (FR-023) needs no second guard anywhere.
 */
final class PendingPrivateRequest
{
    public static function settle(
        PrivateSessionRequest $request,
        string $status,
        ?User $decider = null,
        ?string $reason = null,
        ?int $classSessionId = null,
    ): bool {
        $update = [
            'status' => $status,
            'decided_by' => $decider?->getKey(),
            'decided_at' => now(),
            'decision_reason' => $reason,
            // The row's own id: unique by construction, so every settled row
            // coexists with every other and only the live ones compete.
            'pending_slot' => $request->getKey(),
        ];

        if ($classSessionId !== null) {
            $update['class_session_id'] = $classSessionId;
        }

        return PrivateSessionRequest::query()
            // The student is a member of no workspace, so the scope is inert for
            // them and would filter by the READER's workspace for anyone else —
            // the sweep runs with no context at all.
            ->withoutWorkspaceScope()
            ->whereKey($request->getKey())
            ->where('status', PrivateSessionRequest::PENDING)
            ->update($update) > 0;
    }
}
