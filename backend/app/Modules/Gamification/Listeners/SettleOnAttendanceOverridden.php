<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Gamification\Actions\ReinstateAward;
use App\Modules\Gamification\Actions\ReverseAward;
use App\Modules\Gamification\Support\AwardChain;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceOverridden;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A teacher changed a mark ⇒ the attendance points follow it, in BOTH directions
 * (FR-010).
 *
 * ⚠️ THIS EVENT, AND NOT `SessionCancelled`, WHICH THE DESIGN ORIGINALLY NAMED.
 * The written trigger was impossible: attendance is confirmed when a session
 * COMPLETES, and cancelling throws on a session in a final state — so a session
 * that has awarded anything can never be cancelled afterwards. An override is the
 * real moment an attendance award becomes false, and the event already exists and
 * already carries the row.
 *
 * ⚠️ AND IT HAS TO GO BACK AS WELL AS FORWARD. This listener used to reverse on
 * `Absent` and return on everything else — so a teacher who marked a student
 * absent by mistake and corrected it a minute later took the points away for
 * ever: nothing re-awarded, and `AwardPoints` could not have anyway, because the
 * original still holds the idempotency key. Now a change back to attendance
 * reinstates what the reversal took (`AwardChain`), and any number of round trips
 * ends with the cause paid exactly once or not at all.
 *
 * ⚠️ ONLY A CAUSE THAT PAID ONCE IS SETTLED HERE. A student marked absent when
 * the session closed and corrected to present afterwards has no original — they
 * were never awarded — and awarding them from here would need the session's
 * workspace and completion state, which this module reads only through a
 * contract. That is a separate question, deliberately not answered by this fix.
 */
class SettleOnAttendanceOverridden implements ShouldQueue
{
    public function __construct(
        private readonly AwardChain $chain,
        private readonly ReverseAward $reverse,
        private readonly ReinstateAward $reinstate,
    ) {}

    public function handle(AttendanceOverridden $event): void
    {
        $original = $this->chain->original(
            (int) $event->attendance->student_user_id,
            'session_attended',
            'class_session',
            (int) $event->attendance->class_session_id,
        );

        // Nothing to settle: the cap may have been full when the session settled,
        // or the action may have been disabled. Neither is an error.
        if ($original === null) {
            return;
        }

        // Only `Absent` invalidates the award. Present ⇄ late ⇄ excused are all
        // still attendance, and excused especially: it is the teacher's decision
        // that the absence is not held against the student, so taking their points
        // would contradict the hand that granted it.
        if ($event->attendance->status === AttendanceStatus::Absent) {
            $this->reverse->handle($original);

            return;
        }

        $this->reinstate->handle($original);
    }
}
