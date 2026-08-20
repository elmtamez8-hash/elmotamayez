<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Enums\FocusSessionStatus;
use App\Modules\Gamification\Models\FocusSession;
use App\Shared\Actions\Action;

/**
 * Close a study session and pay for it if it was finished (FR-040).
 *
 * ⚠️ WHETHER IT COUNTS AS COMPLETE IS THE SERVER'S DECISION, taken from the clock
 * — never from anything the client sends. Trusting the caller makes a "120 minute"
 * session completable in five seconds, and the daily cap is then the only thing
 * between a student and an afternoon of free experience.
 *
 * ⚠️ AND AN INTERRUPTED SESSION EARNS NOTHING RATHER THAN A FRACTION. A part-paid
 * session is an incentive to start and abandon repeatedly, which is precisely the
 * behaviour the cap exists to stop — and "how much of it counts" is a number
 * nobody could defend. Finished or not.
 */
class EndFocusSession extends Action
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(FocusSession $session): FocusSession
    {
        if ($session->status !== FocusSessionStatus::Running) {
            return $session;
        }

        $elapsedMinutes = $session->started_at->diffInMinutes(now());
        $completed = $elapsedMinutes >= $session->planned_minutes;

        $session->forceFill([
            'status' => $completed ? FocusSessionStatus::Completed : FocusSessionStatus::Interrupted,
            'ended_at' => now(),
        ])->save();

        if ($completed) {
            $this->award->handle(new AwardRequest(
                studentUserId: (int) $session->user_id,
                actionKey: 'focus_session',
                // The session itself is the cause, so a replayed request pays once.
                sourceType: 'focus_session',
                sourceId: (int) $session->getKey(),
                // No workspace: studying belongs to no teacher, which is also why
                // the action carries zero coins — there is no purse to put them in.
                workspaceId: null,
            ));
        }

        return $session->refresh();
    }
}
