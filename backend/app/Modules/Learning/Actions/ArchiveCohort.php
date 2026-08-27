<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\PendingTransfer;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * The end of a run — and the only thing that ever happens to a group that is
 * finished with (FR-035).
 *
 * ⚠️ THERE IS NO DELETE, AND THERE IS NO `SoftDeletes` EITHER. A soft delete
 * puts the row behind a global scope, which is exactly where the teacher's own
 * screen and the audit cannot see the thing they just acted on — every closed
 * membership would point at a group that no longer resolves, and the history
 * that FR-034 promises would read as corruption. Precedent: `hidden_at` rather
 * than `deleted_at`, 010.
 *
 * ⚠️ AND EVERY REQUEST AIMED AT IT IS DROPPED IN THE SAME BREATH. A pending
 * request to join an archived group can never be approved by anybody: left
 * alone it is a row in the teacher's queue with no possible decision, and a
 * student watching for an answer that cannot come.
 *
 * Open memberships are deliberately NOT closed. The students in the room stay
 * in it — archiving says "nobody new, and it is over", not "everybody out",
 * and closing them here would strip their timetable and their thread the
 * instant a teacher tidied up last term.
 */
class ArchiveCohort extends Action
{
    public function handle(Cohort $cohort, User $actor): Cohort
    {
        if ($cohort->status === Cohort::ARCHIVED) {
            return $cohort;
        }

        DB::transaction(function () use ($cohort, $actor): void {
            $cohort->forceFill([
                'status' => Cohort::ARCHIVED,
                'archived_at' => now(),
            ])->save();

            $pending = CohortTransferRequest::query()
                ->withoutWorkspaceScope()
                ->where('to_cohort_id', $cohort->getKey())
                ->where('status', CohortTransferRequest::PENDING)
                ->get();

            foreach ($pending as $request) {
                PendingTransfer::drop(
                    (int) $request->course_id,
                    (int) $request->student_user_id,
                    $actor,
                    'أُرشفت المجموعة المطلوبة.',
                );
            }
        });

        return $cohort->refresh();
    }
}
