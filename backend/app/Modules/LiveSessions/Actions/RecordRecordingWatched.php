<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;

/**
 * Notes that somebody watched the recording afterwards.
 *
 * An independent fact, and nothing more (FR-021د · SC-023). It appears on the
 * teacher's register («شاهد التسجيل لاحقاً») and it is a fair reason for a
 * teacher to mark someone present by hand — but it never changes the status by
 * itself, and it touches no money: consumption is per seat, not per attendee.
 *
 * An earlier draft of the spec let a full watch flip Absent to Present. That was
 * dropped for two reasons: attendance carries no financial weight at all, and
 * conflating "was in the lesson" with "watched the video later" empties the
 * status of the meaning that makes it worth reporting to a parent.
 *
 * Reached in production from `RecordWatchedOnSustainedPlayback`, i.e. from the
 * playback renewal loop crossing the watched threshold on the server clock.
 *
 * Three guards, each load-bearing:
 *  - ONCE. `WHERE recording_watched_at IS NULL` is both the check and the write,
 *    so the first watch is what the register shows and a second viewing (or two
 *    renewals racing) never moves it.
 *  - NOT THE HOST. The teacher has an attendance row of their own on purpose
 *    (`CloseClassSession` judges delivery from it), and `IssuePlaybackGrant`
 *    lets them watch their own recording — without this their row would read
 *    «شاهد التسجيل لاحقاً» about the lesson they taught.
 *  - UNSCOPED, with the session id as the guard. A student stamped with another
 *    teacher's `last_workspace_id` would otherwise match nothing and the fact
 *    would silently never record.
 *
 * Only a person who HAS a register row can be recorded: a stranger has none, so
 * nothing is created for them.
 */
class RecordRecordingWatched extends Action
{
    /** @return bool whether this call is the one that recorded the fact */
    public function handle(ClassSession $session, User $student): bool
    {
        $hostUserId = TeacherProfile::withoutWorkspaceScope()
            ->whereKey($session->teacher_profile_id)
            ->value('user_id');

        if ($hostUserId !== null && (int) $hostUserId === (int) $student->getKey()) {
            return false;
        }

        // Only this column. `status` is not in the payload on purpose.
        return Attendance::withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->whereNull('recording_watched_at')
            ->update(['recording_watched_at' => now()]) > 0;
    }
}
