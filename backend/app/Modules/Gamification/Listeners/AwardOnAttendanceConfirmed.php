<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\LiveSessions\Events\AttendanceConfirmed;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Attendance is settled ⇒ everyone who was there earns for it.
 *
 * ⚠️ THE EVENT CARRIES THE SESSION, NOT THE PEOPLE, so the list has to be asked
 * for — and it is asked through a CONTRACT rather than by loading LiveSessions'
 * models here (Constitution III).
 *
 * ⚠️ AND THE HOST IS EXCLUDED INSIDE THAT CONTRACT. The teacher has an attendance
 * row on purpose — CloseClassSession judges delivery, and therefore their pay,
 * from it — so without the exclusion a teacher would earn attendance points for
 * every lesson they teach and sit permanently at the top of their own students'
 * leaderboard.
 *
 * Queued: awarding must not add measurable time to the operation that triggered
 * it (SC-016), and a register with thirty seats is thirty awards.
 */
class AwardOnAttendanceConfirmed implements ShouldQueue
{
    public function __construct(
        private readonly AwardPoints $award,
        private readonly SessionAttendanceDirectory $attendance,
    ) {}

    public function handle(AttendanceConfirmed $event): void
    {
        $session = $event->session;

        foreach ($this->attendance->attendeeUserIds((int) $session->getKey()) as $userId) {
            $this->award->handle(new AwardRequest(
                studentUserId: $userId,
                actionKey: 'session_attended',
                // The CAUSE, never the moment. These two plus the student and the
                // action are the idempotency key, so a timestamp here would make
                // every redelivery a fresh award.
                sourceType: 'class_session',
                sourceId: (int) $session->getKey(),
                workspaceId: (int) $session->workspace_id,
                courseId: $session->course_id === null ? null : (int) $session->course_id,
            ));
        }
    }
}
