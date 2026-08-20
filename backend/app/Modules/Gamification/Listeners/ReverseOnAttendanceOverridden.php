<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Gamification\Actions\ReverseAward;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceOverridden;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A teacher changed a mark to absent ⇒ the attendance points come back (FR-010).
 *
 * ⚠️ THIS EVENT, AND NOT `SessionCancelled`, WHICH THE DESIGN ORIGINALLY NAMED.
 * The written trigger was impossible: attendance is confirmed when a session
 * COMPLETES, and cancelling throws on a session in a final state — so a session
 * that has awarded anything can never be cancelled afterwards. FR-010 had exactly
 * one route to it and that route was unreachable, which is the kind of feature
 * that ships, reports success, and does nothing for ever.
 *
 * An override is the real moment an attendance award becomes false, and the event
 * already exists and already carries the row.
 */
class ReverseOnAttendanceOverridden implements ShouldQueue
{
    public function __construct(private readonly ReverseAward $reverse) {}

    public function handle(AttendanceOverridden $event): void
    {
        // Only a change TO absent invalidates the award. Present ⇄ late ⇄ excused
        // are all still attendance, and excused especially: it is the teacher's
        // decision that the absence is not held against the student, so taking
        // their points would contradict the hand that granted it.
        if ($event->attendance->status !== AttendanceStatus::Absent) {
            return;
        }

        $original = AwardEntry::query()
            ->where('student_user_id', $event->attendance->student_user_id)
            ->where('action_key', 'session_attended')
            ->where('source_type', 'class_session')
            ->where('source_id', $event->attendance->class_session_id)
            ->where('reversal_of_id', 0)
            ->first();

        // Nothing to undo: the cap may have been full when the session settled, or
        // the action may have been disabled. Neither is an error.
        if ($original === null) {
            return;
        }

        $this->reverse->handle($original);
    }
}
