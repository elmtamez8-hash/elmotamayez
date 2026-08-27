<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Events\CohortTransferDecided;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\Learning\Support\CohortRefusal;
use App\Modules\Learning\Support\PendingTransfer;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The teacher's answer (FR-028ز · FR-028ح).
 *
 * ⚠️ THE SEAT IS CLAIMED HERE, WHICH MEANS AN APPROVAL CAN FAIL. A request that
 * was perfectly valid the day it was submitted is refused with `cohort_full` if
 * the group filled up in between — and the student's EXISTING membership is not
 * touched by that failure, because the whole point of not moving anything at
 * submission is that a refusal costs them nothing.
 *
 * ⚠️ A REJECTION NEEDS A WRITTEN REASON, AND THE REFUSAL IS RAISED BEFORE
 * ANYTHING IS WRITTEN. A silent refusal reads as a fault and is submitted again
 * for ever, which is a queue the teacher then has to clear twice.
 *
 * ⚠️ AND THE REJECTION IS RECORDED EXACTLY AS THE APPROVAL IS (FR-033). A
 * history that keeps only what was accepted shows a student who asked three
 * times and was refused as a student who never asked for anything.
 */
class DecideTransferRequest extends Action
{
    public function handle(
        CohortTransferRequest $request,
        User $decider,
        bool $approve,
        ?string $reason = null,
    ): CohortTransferRequest {
        if (! $request->isPending()) {
            throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
        }

        if (! $approve && trim((string) $reason) === '') {
            throw new CohortRefusal('reason_required', 'سبب الرفض مطلوب — الطالب يقرؤه.');
        }

        if (! $approve) {
            return DB::transaction(function () use ($request, $decider, $reason): CohortTransferRequest {
                if (! PendingTransfer::settle($request, CohortTransferRequest::REJECTED, $decider, $reason)) {
                    throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
                }

                CohortMembershipWriter::record((int) $request->workspace_id, [
                    'course_id' => $request->course_id,
                    'student_user_id' => $request->student_user_id,
                    'cohort_id' => $request->to_cohort_id,
                    'from_cohort_id' => $request->from_cohort_id,
                    'event' => CohortMembershipEvent::REJECTED,
                    'actor_user_id' => $decider->getKey(),
                    'reason' => $reason,
                ]);

                $request->refresh();

                // FR-028ح — «وأن يُخطَرَ بالقرارِ قبولاً أو رفضاً». The refusal
                // is the half the requirement is about: unannounced, it is
                // resubmitted for ever.
                event(new CohortTransferDecided($request, approved: false));

                return $request;
            });
        }

        $cohort = Cohort::query()
            ->withoutWorkspaceScope()
            ->whereKey($request->to_cohort_id)
            ->first();

        if ($cohort === null) {
            throw CohortRefusal::closed();
        }

        $student = $request->student;

        if ($student === null) {
            // The relation is a foreign key that always resolves in practice; a
            // null here means the row is corrupt, and moving a membership for
            // "nobody" would write a row nothing can ever read back.
            throw new DomainException('حساب الطالب صاحب الطلب غير موجود.');
        }

        return DB::transaction(function () use ($request, $cohort, $student, $decider, $reason): CohortTransferRequest {
            /*
            | ⚠️ THE MOVE COMES FIRST, AND THAT ORDER IS THE POINT. It is the
            | statement that can refuse — a full group, an archived one, a race
            | with another decider — and everything below it is bookkeeping about
            | a move that happened. Settling the request first would leave an
            | `approved` row over a student who never moved.
            */
            CohortMembershipWriter::open(
                $cohort,
                $student,
                CohortMembershipEvent::TRANSFERRED,
                $decider,
                $reason,
            );

            if (! PendingTransfer::settle($request, CohortTransferRequest::APPROVED, $decider, $reason)) {
                throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
            }

            CohortMembershipWriter::record((int) $request->workspace_id, [
                'course_id' => $request->course_id,
                'student_user_id' => $request->student_user_id,
                'cohort_id' => $request->to_cohort_id,
                'from_cohort_id' => $request->from_cohort_id,
                'event' => CohortMembershipEvent::APPROVED,
                'actor_user_id' => $decider->getKey(),
                'reason' => $reason,
            ]);

            $request->refresh();

            event(new CohortTransferDecided($request, approved: true));

            return $request;
        });
    }
}
