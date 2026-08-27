<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\CohortTransferRequest;

/**
 * The pending request, and the one statement that settles it.
 *
 * ⚠️ SETTLING IS A CONDITIONAL UPDATE ON `status = pending`, not a read followed
 * by a save. Two teachers with the queue open on two screens both read a pending
 * row; written the obvious way both then decide it, and the student is told
 * «approved» and «rejected» about the same request, with a seat claimed by the
 * losing decision still gone. The statement is both the check and the claim —
 * the `captured_order_id` idiom — and `pending_slot` moves off its zero sentinel
 * inside it, so the unique index frees up the moment the row stops being live.
 *
 * ⚠️ AND `dropped` IS NOT DECORATION. A request pointing at an archived group,
 * or belonging to a student the teacher has already moved by hand, can never be
 * approved by anybody: left pending it is a queue that grows and is never read,
 * and the student watches a decision that will never come.
 */
final class PendingTransfer
{
    public static function forStudent(int $courseId, int $studentUserId): ?CohortTransferRequest
    {
        return CohortTransferRequest::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            ->where('student_user_id', $studentUserId)
            ->where('status', CohortTransferRequest::PENDING)
            ->first();
    }

    /**
     * Move a pending request to a terminal state. `false` means somebody else
     * got there first.
     */
    public static function settle(
        CohortTransferRequest $request,
        string $status,
        ?User $decider,
        ?string $reason,
    ): bool {
        return CohortTransferRequest::query()
            ->withoutWorkspaceScope()
            ->whereKey($request->getKey())
            ->where('status', CohortTransferRequest::PENDING)
            ->update([
                'status' => $status,
                'decided_by' => $decider?->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
                'pending_slot' => $request->getKey(),
            ]) > 0;
    }

    /**
     * Whatever this student had pending in this course is now moot — because
     * the teacher moved them by hand, took them out, or archived the group they
     * were aiming at.
     *
     * The reason is written down rather than left blank: a request that
     * disappeared with no explanation is indistinguishable from a fault, and
     * gets submitted again.
     */
    public static function drop(int $courseId, int $studentUserId, ?User $actor, string $reason): void
    {
        $request = self::forStudent($courseId, $studentUserId);

        if ($request === null) {
            return;
        }

        if (! self::settle($request, CohortTransferRequest::DROPPED, $actor, $reason)) {
            return;
        }

        CohortMembershipWriter::record((int) $request->workspace_id, [
            'course_id' => $request->course_id,
            'student_user_id' => $request->student_user_id,
            'cohort_id' => $request->to_cohort_id,
            'from_cohort_id' => $request->from_cohort_id,
            'event' => CohortMembershipEvent::REQUEST_DROPPED,
            'actor_user_id' => $actor?->getKey(),
            'reason' => $reason,
        ]);
    }
}
