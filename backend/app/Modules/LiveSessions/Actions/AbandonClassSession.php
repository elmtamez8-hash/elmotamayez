<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;

/**
 * The lesson that never started, and the missing writer of `Interrupted`.
 *
 * ⚠️ `ClassSessionStatus::Interrupted` HAD THREE READERS AND NOT ONE PRODUCER.
 * `CloseStaleSessionsJob` selected it, `SyncTeacherCountersJob` counted it in the
 * denominator, and `countsTowardsCounters()` excluded it from the numerator —
 * three consumers of a value nothing in the tree could ever write.
 *
 * What that cost: every job that ends a session — the absentee sweep and the
 * delayed close — is dispatched from `OpenBroadcastRoom`. So a teacher who never
 * opened the room had NOTHING scheduled against their session: the seats stayed
 * held for ever, no register existed, no guardian heard anything, and the session
 * sat `scheduled` past its end with no sweep selecting it. The counter moved the
 * wrong way too — a teacher who opened and left early was marked down, while one
 * who never turned up at all was in neither side of the ratio. FR-062 inverted:
 * missing the lesson entirely was cheaper than teaching half of it.
 *
 * ⚠️ SEPARATE FROM `CloseClassSession`, NOT A BRANCH INSIDE IT. That Action means
 * "the lesson ran, settle what happened in it" and its first act is a provider
 * call to close a room. Nothing here ran and no room exists. Keeping them apart
 * also keeps the discriminator out of the hot path: the ONLY caller that can ever
 * see a `Scheduled` session past its end is the hourly sweep, because the delayed
 * close is dispatched by the room opening and therefore only ever sees `Live`.
 *
 * ⚠️ NO REGISTER IS WRITTEN. `CloseClassSession::completeRegister()` writes an
 * `Absent` row for every frozen seat, and `SendSessionReportsJob` would then tell
 * every guardian their child missed a class that was never held. Nobody was
 * absent from a lesson nobody taught. Nothing is delivered either, so no seat is
 * charged and no unit accrues — the students keep their credits.
 *
 * ⚠️ AND `SessionCompleted` IS NOT DISPATCHED, THOUGH THE COUNTER SYNC IS. That
 * event means "the register is closed", and there is no register. Its other
 * listener sends `IngestSessionRecordingJob`, which would interrogate a provider
 * about a room that never existed — and an empty egress list is indistinguishable
 * from "still encoding", so the session would be swept every fifteen minutes for
 * two days and then written `failed`: a verdict about a file that was never going
 * to be. The teacher's counters are the one real consequence, so they are asked
 * for directly rather than through an event that would carry a second, false one.
 */
class AbandonClassSession extends Action
{
    /** Written to `interruption_note` when the teacher never opened the room. */
    public const TEACHER_NO_SHOW = 'teacher_no_show';

    public function handle(ClassSession $session): ClassSession
    {
        if ($session->status !== ClassSessionStatus::Scheduled) {
            return $session;
        }

        $session->forceFill([
            'status' => ClassSessionStatus::Interrupted,
            'interruption_note' => self::TEACHER_NO_SHOW,
        ])->save();

        SyncTeacherCountersJob::dispatch((int) $session->teacher_profile_id);

        return $session;
    }
}
