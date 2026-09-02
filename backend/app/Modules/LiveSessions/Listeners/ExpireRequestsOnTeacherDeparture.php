<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\LiveSessions\Events\PrivateSessionExpired;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Support\PendingPrivateRequest;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * The teacher left; nobody is coming to answer (FR-026).
 *
 * ⚠️ LEFT PENDING, THESE ARE A QUEUE WITH NOBODY BEHIND IT. The expiry sweep
 * would eventually reach them, but «eventually» is up to two days of a student
 * watching a decision that can never arrive — and the deadline is an operator's
 * number, so it could be a week. The departure is the answer, and it is known
 * now.
 *
 * ⚠️ IT REUSES `expired` RATHER THAN INVENTING A SIXTH STATUS. From the
 * student's side the fact is identical — «no answer came, nothing was charged,
 * ask again» — and a status nothing reads differently is a state machine that
 * grew a branch for the writer's benefit. What differs is who they would ask
 * again; that is a property of the workspace, not of this row.
 *
 * ⚠️ AND IT NEVER CANCELS AN ACCEPTED ONE. 013's FR-035 keeps a student's paid
 * access until their term ends, so a lesson already scheduled stays scheduled —
 * only the unanswered asks end here.
 */
class ExpireRequestsOnTeacherDeparture implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function handle(TeacherOffboardingCompleted $event): void
    {
        PrivateSessionRequest::query()
            // The offboarding officer's own workspace is very likely not this
            // one, and `WorkspaceContext::id()` falls back to their
            // `last_workspace_id` — so a scoped query here ANDs the wrong id,
            // matches zero rows, and the departure silently ends nothing.
            ->withoutWorkspaceScope()
            ->where('workspace_id', $event->offboarding->workspace_id)
            ->pending()
            ->orderBy('id')
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    try {
                        // Only the winner notifies: a teacher pressing accept as
                        // their access is revoked settles the row first, and the
                        // student is not told a granted lesson expired.
                        if (PendingPrivateRequest::settle($request, PrivateSessionRequest::EXPIRED)) {
                            PrivateSessionExpired::dispatch($request->refresh());
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
