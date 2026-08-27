<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Learning\Events\CohortTransferRequested;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\Learning\Support\CohortRefusal;
use App\Modules\Learning\Support\PendingTransfer;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * «أريد الانتقال إلى الأحد ٦م» (FR-028هـ).
 *
 * ⚠️ IT TOUCHES THE EXISTING MEMBERSHIP WITH NOTHING (FR-028و). Not a column,
 * not a flag, not a "pending" state on the row. The student keeps their group,
 * their timetable, their seats and their thread until the moment somebody
 * approves — otherwise they leave one place before entering another, waiting on
 * an answer that may never come.
 *
 * ⚠️ AND CAPACITY IS NOT MEASURED HERE. Two students may both ask for the last
 * seat; refusing the second at submission would be measuring a number that has
 * to be true at a different moment. The seat is claimed inside
 * {@see DecideTransferRequest}, which is where it can actually be held.
 *
 * ⚠️ `same_cohort` IS REFUSED AT SUBMISSION, THOUGH. It cannot become true
 * later, so a queue carrying it is a queue with a row in it that has no possible
 * decision.
 */
class RequestTransfer extends Action
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly CohortDirectory $cohorts,
    ) {}

    public function handle(Cohort $to, User $student, ?string $reason = null): CohortTransferRequest
    {
        $courseId = (int) $to->course_id;

        if (! $this->enrollments->hasActiveEnrollment($student, $courseId)) {
            throw CohortRefusal::notEnrolled();
        }

        $currentCohortId = $this->cohorts->openMembershipCohortId($student, $courseId);

        // No membership means there is nothing to transfer FROM — and joining is
        // free, so sending them through an approval queue would invent a gate
        // FR-028د exists to remove.
        if ($currentCohortId === null) {
            throw CohortRefusal::noMembership();
        }

        if ($currentCohortId === (int) $to->getKey()) {
            throw CohortRefusal::sameCohort();
        }

        if ($to->status !== Cohort::OPEN) {
            throw CohortRefusal::closed();
        }

        if (PendingTransfer::forStudent($courseId, (int) $student->getKey()) !== null) {
            throw CohortRefusal::requestPending();
        }

        return DB::transaction(function () use ($to, $student, $reason, $courseId, $currentCohortId): CohortTransferRequest {
            try {
                $request = CohortTransferRequest::query()->create([
                    'workspace_id' => $to->workspace_id,
                    'course_id' => $courseId,
                    'student_user_id' => $student->getKey(),
                    'to_cohort_id' => $to->getKey(),
                    'from_cohort_id' => $currentCohortId,
                    'student_reason' => $reason,
                ]);
                // ⚠️ REFRESHED, BECAUSE `status` AND `pending_slot` ARE NOT
                // FILLABLE. Their values come from the column defaults, so the
                // instance `create()` hands back carries NULL for both — and the
                // Resource would send `status: null` while the row says
                // `pending`, which is a payload nothing in the client matches.
                $request->refresh();
            } catch (UniqueConstraintViolationException) {
                // `unique(student, course, pending_slot)` with its zero sentinel
                // is what makes "one pending request" true under concurrency —
                // the read above only makes it readable.
                throw CohortRefusal::requestPending();
            }

            CohortMembershipWriter::record((int) $to->workspace_id, [
                'course_id' => $courseId,
                'student_user_id' => $student->getKey(),
                'cohort_id' => $to->getKey(),
                'from_cohort_id' => $currentCohortId,
                'event' => CohortMembershipEvent::REQUESTED,
                'actor_user_id' => $student->getKey(),
                'reason' => $reason,
            ]);

            // FR-028ح — the teacher hears about it. Fired inside the
            // transaction with an after-commit listener, so a worker can never
            // read the row before it exists.
            event(new CohortTransferRequested($request));

            return $request;
        });
    }
}
