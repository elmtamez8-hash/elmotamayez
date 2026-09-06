<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A batch of existing sessions now belongs to a group (027 · FR-040).
 *
 * ⚠️ A SECOND EVENT, NOT A RE-DISPATCH OF `SessionScheduled`. «A session was
 * created» and «a session joined a group» are two different facts, and one name
 * for both is the `SessionCompleted`/`SessionDelivered` split coming back: a
 * listener that only cares about one of them would have to guess which it got.
 *
 * ⚠️ AND IT EXISTS AT ALL BECAUSE THE WRITE IS A BULK `update()`. That fires no
 * model events, so without this the automatic booking works on the scheduling
 * path and is silent on the assignment path — half a feature, with nothing
 * reporting the half that is missing.
 *
 * ⚠️ AND IT CARRIES THE WHOLE BATCH, NOT ONE SESSION. One press assigns a term
 * of lessons, so a per-session event would make one refusal notice per lesson per
 * subscriber. The batch is what lets the listener send one.
 *
 * Ids rather than models: the rows were just written by a bulk UPDATE, so any
 * model in hand is already stale, and a queued listener must re-read them anyway.
 */
class SessionsAssignedToCohort
{
    use Dispatchable;

    /**
     * @param  list<int>  $sessionIds
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $courseId,
        public readonly int $cohortId,
        public readonly array $sessionIds,
    ) {}
}
