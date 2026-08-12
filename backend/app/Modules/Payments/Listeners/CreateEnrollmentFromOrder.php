<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * When a manual payment is approved, auto-enroll the student in the order's course.
 *
 * Reuses the EnrollStudent action so the same EnrollmentCreated event pipeline fires
 * (notifications, activity log, etc.).
 *
 * ShouldHandleEventsAfterCommit because ApproveOrder fires PaymentApproved from
 * INSIDE its own DB::transaction. Without it this job is pushed to the queue
 * while that transaction is still open, a worker picks it up within
 * milliseconds, and it reads the order as `pending` — or does not find it at
 * all. The student has paid and got nothing, with no retry, because the job
 * "succeeded". Same reason as CompleteExamLessonOnSubmission.
 */
class CreateEnrollmentFromOrder implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly EnrollStudent $enrollStudent,
        private readonly WorkspaceContext $workspace,
    ) {}

    /**
     * ⚠️ Typed on the CONTRACT, not on PaymentApproved — the manual approval and
     * the gateway capture both enrol, and a class type here threw a TypeError on
     * the first successful gateway payment. This listener is the constitution's
     * eighth critical path; its whole route runs before this phase is committed.
     */
    public function handle(CarriesPaidOrder $event): void
    {
        $order = $event->order();

        // A credit order carries a course too — that is the whole point of Q-7 —
        // so `course_id === null` no longer separates the two. Without this
        // branch, buying credits would hand the student the entire course free.
        if ($order->kind !== OrderKind::Course) {
            return;
        }

        if ($order->course_id === null) {
            return;
        }

        $course = $order->course;

        if ($course === null) {
            return;
        }

        // Scoped to this job only — set() would leak the workspace into the next
        // job handled by the same worker process.
        $this->workspace->forWorkspace($order->workspace, fn () => $this->enrollStudent->handle(
            course: $course,
            student: $order->user,
            source: 'purchase',
            orderId: $order->getKey(),
        ));
    }
}
