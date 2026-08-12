<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Events\PaymentFailed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the payer what happened to their money.
 *
 * One listener for both outcomes, two entry points: they are the same fact with
 * the sign flipped, and two files is where the failure path quietly stops being
 * maintained — the success path is the one everybody exercises by hand.
 *
 * ⚠️ NO AMOUNT IN EITHER MESSAGE. A credit's price is the teacher's settlement
 * rate plus two platform constants, so a total in a student's notification is
 * solvable for what the teacher is paid (FR-035). The course and the outcome are
 * what the reader needs; the number is on the billing screen.
 */
class NotifyPaymentOutcome implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handleCaptured(PaymentCaptured $event): void
    {
        $this->dispatch->handle(new NotificationRequest(
            recipient: $event->order->user,
            type: NotificationType::PaymentConfirmed,
            variables: ['course' => $event->order->course->title ?? ''],
            workspaceId: $event->order->workspace_id,
        ));
    }

    public function handleFailed(PaymentFailed $event): void
    {
        $this->dispatch->handle(new NotificationRequest(
            recipient: $event->order->user,
            type: NotificationType::PaymentFailed,
            variables: [
                'course' => $event->order->course->title ?? '',
                // FR-008 — the sentence the student reads. Never the provider's
                // raw error, which names systems they have no relationship with.
                'reason' => $event->reason ?? 'سبب غير محدَّد من مزوّد الدفع.',
            ],
            workspaceId: $event->order->workspace_id,
        ));
    }
}
