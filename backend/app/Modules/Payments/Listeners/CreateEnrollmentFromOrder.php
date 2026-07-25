<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Payments\Events\PaymentApproved;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * When a manual payment is approved, auto-enroll the student in the order's course.
 *
 * Reuses the EnrollStudent action so the same EnrollmentCreated event pipeline fires
 * (notifications, activity log, etc.).
 */
class CreateEnrollmentFromOrder implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly EnrollStudent $enrollStudent,
        private readonly WorkspaceContext $workspace,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $order = $event->order;

        if ($order->course_id === null) {
            return;
        }

        $course = $order->course;

        if ($course === null) {
            return;
        }

        $this->workspace->set($order->workspace);

        $this->enrollStudent->handle(
            course: $course,
            student: $order->user,
            source: 'purchase',
            orderId: $order->getKey(),
        );
    }
}
