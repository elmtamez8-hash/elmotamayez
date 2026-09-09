<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Events\SessionRescheduleDecided;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Modules\LiveSessions\Support\PendingRescheduleRequest;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionAttendanceDirectory;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The teacher's answer — and the only moment the timetable changes.
 *
 * ⚠️ ONE ROW MOVES, AND THAT IS THE WHOLE OF «الحصّة التالية في موعدها
 * الطبيعيّ». A group's lessons are dated rows, not references to a weekly slot,
 * so the next Saturday is a different row that nothing here touches. There is no
 * «temporary» flag, no exception table and no restore job, because there is
 * nothing to restore.
 *
 * ⚠️ THE MOVE GOES THROUGH {@see UpdateClassSession}, NEVER A `save()` HERE.
 * That action is where the overlap check and the freeze check live — so an
 * approval that would drop this group's hour on top of another group's is
 * refused with a sentence the teacher can act on, and the request stays pending
 * for them to answer differently. An acceptance that can fail is the point:
 * asking held nothing, so losing it costs the student nothing either.
 *
 * ⚠️ AND THE ORDER IS: THE MOVE, THEN THE SETTLE, WITH THE LOSER THROWN FROM
 * INSIDE THE TRANSACTION. Settling first would leave an `approved` row standing
 * over a lesson that never moved; reporting the lost race to a caller outside the
 * closure would leave the lesson moved behind a request somebody else refused.
 */
class DecideSessionRescheduleRequest extends Action
{
    public function __construct(
        private readonly UpdateClassSession $update,
        private readonly SessionAttendanceDirectory $attendance,
    ) {}

    public function handle(
        SessionRescheduleRequest $request,
        User $decider,
        bool $approve,
        ?string $reason = null,
    ): SessionRescheduleRequest {
        if (! $request->isPending()) {
            throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
        }

        return $approve
            ? $this->approve($request, $decider)
            : $this->reject($request, $decider, $reason);
    }

    /**
     * A refusal carries the teacher's own words, demanded before anything is
     * written.
     *
     * A silent refusal is indistinguishable from a request still waiting, so it
     * is asked again — which is a queue the teacher then clears twice.
     */
    private function reject(SessionRescheduleRequest $request, User $decider, ?string $reason): SessionRescheduleRequest
    {
        if (trim((string) $reason) === '') {
            throw new DomainException('سبب الرفض مطلوب — الطالب يقرؤه.');
        }

        if (! PendingRescheduleRequest::settle($request, SessionRescheduleRequest::REJECTED, $decider, $reason)) {
            throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
        }

        $request->refresh();

        SessionRescheduleDecided::dispatch($request, false);

        return $request;
    }

    private function approve(SessionRescheduleRequest $request, User $decider): SessionRescheduleRequest
    {
        $session = ClassSession::query()
            // The decider is a workspace member and the scope would answer
            // correctly — named anyway, so the filter is visible to whoever edits
            // this next, and so a queued caller with no context still resolves.
            ->withoutWorkspaceScope()
            ->find($request->class_session_id);

        if ($session === null) {
            throw new DomainException('الحصة المطلوب تأجيلها لم تعد موجودة.');
        }

        /*
        | Read before the move for legibility, not for correctness: nothing here
        | releases a seat, so the list is the same on both sides of the UPDATE.
        | (`SessionCancelled` carries its holders because cancelling DOES release
        | them — the two rules look alike and are not the same rule.)
        */
        $seatHolderIds = $this->attendance->seatHolderUserIds(
            (int) $session->getKey(),
            (int) $session->workspace_id,
        );

        DB::transaction(function () use ($request, $session, $decider): void {
            $this->update->handle($session, ['starts_at' => $request->to_starts_at]);

            if (! PendingRescheduleRequest::settle($request, SessionRescheduleRequest::APPROVED, $decider)) {
                throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
            }
        });

        $request->refresh();

        SessionRescheduleDecided::dispatch($request, true, $seatHolderIds);

        return $request;
    }
}
