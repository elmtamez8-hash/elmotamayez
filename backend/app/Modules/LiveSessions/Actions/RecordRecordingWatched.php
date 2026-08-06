<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;

/**
 * Notes that somebody watched the recording afterwards.
 *
 * An independent fact, and nothing more (FR-021د · SC-023). It appears in
 * reports and it is a fair reason for a teacher to mark someone present by hand
 * — but it never changes the status by itself.
 *
 * An earlier draft of the spec let a full watch flip Absent to Present. That was
 * dropped for two reasons: attendance carries no financial weight at all
 * (consumption is per seat, not per attendee), and conflating "was in the
 * lesson" with "watched the video later" empties the status of the meaning that
 * makes it worth reporting to a parent.
 */
class RecordRecordingWatched extends Action
{
    public function handle(ClassSession $session, User $student): ?Attendance
    {
        $attendance = Attendance::query()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->first();

        if ($attendance === null) {
            return null;
        }

        // Only this column. `status` is not in the payload on purpose.
        $attendance->forceFill(['recording_watched_at' => now()])->save();

        return $attendance;
    }
}
