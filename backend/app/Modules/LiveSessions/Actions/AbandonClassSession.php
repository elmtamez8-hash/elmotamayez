<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionCreditHolds;
use Illuminate\Support\Facades\DB;

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

    public function __construct(private readonly SessionCreditHolds $holds) {}

    public function handle(ClassSession $session): ClassSession
    {
        /*
        | ٠٣٥ · T062 — ⛔ THE TRANSITION IS A CONDITIONAL UPDATE, so only the
        | winner releases and dispatches. This was a read (`!== Scheduled`) and
        | then a write, and the sweep that calls it walks a page of rows per hour
        | while a second sweep may already be inside this method — two workers
        | both reading `scheduled`, both writing, both releasing. The release is
        | harmless twice (T057 claims `WHERE settled_at IS NULL`); the counter
        | sync is not free, and a status written twice is a second answer.
        */
        $claimed = DB::table('class_sessions')
            ->where('id', $session->getKey())
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->update([
                'status' => ClassSessionStatus::Interrupted->value,
                'interruption_note' => self::TEACHER_NO_SHOW,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return $session;
        }

        // The in-memory model does not learn about a conditional UPDATE, and
        // every caller reads it.
        $session->forceFill([
            'status' => ClassSessionStatus::Interrupted,
            'interruption_note' => self::TEACHER_NO_SHOW,
        ])->syncChanges();
        $session->setAttribute('status', ClassSessionStatus::Interrupted);

        /*
        | ⚠️ AND THE STATE WITH NO CLOSE AT ALL. The teacher never opened the
        | room, so nothing was ever scheduled against this session: no
        | `CloseClassSessionJob`, no register, no `SessionDelivered` — and
        | therefore no charge and no verdict. Every seat here is a student who
        | turned up to nothing, and their credits go back.
        */
        $this->holds->release((int) $session->getKey());

        SyncTeacherCountersJob::dispatch((int) $session->teacher_profile_id);

        return $session;
    }
}
