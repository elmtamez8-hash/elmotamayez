<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Payments\Events\PaymentFailed;
use App\Modules\Payments\Events\ReceiptApproved;
use App\Modules\Payments\Events\ReceiptRejected;
use App\Modules\Payments\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

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
class NotifyPaymentOutcome implements ShouldQueueAfterCommit
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handleCaptured(PaymentCaptured $event): void
    {
        $order = $event->order;

        /*
        | ⚠️ `describe()`, never `course->title ?? ''`. A credit, store or
        | subscription order carries no course, and the template REQUIRES this
        | variable — so the empty string refused the whole message and the payer
        | of every non-course order was never told their money arrived.
        */
        $this->dispatch->handle(new NotificationRequest(
            recipient: $order->user,
            type: NotificationType::PaymentConfirmed,
            variables: ['course' => $this->describe($order)],
            // Where the thing bought now lives — the same rule as an approved receipt.
            actionUrl: $order->kind === OrderKind::Store ? '/store' : '/billing',
            workspaceId: $order->workspace_id,
        ));
    }

    public function handleFailed(PaymentFailed $event): void
    {
        $order = $event->order;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $order->user,
            type: NotificationType::PaymentFailed,
            variables: [
                'course' => $this->describe($order),
                // FR-008 — the sentence the student reads. Never the provider's
                // raw error, which names systems they have no relationship with.
                'reason' => $event->reason ?? 'سبب غير محدَّد من مزوّد الدفع.',
            ],
            // The order row is where the attempt lives and where it is retried —
            // the same destination a rejected receipt points at.
            actionUrl: '/orders',
            workspaceId: $order->workspace_id,
        ));
    }

    /**
     * A human accepted the receipt — told ONLY where nothing else says so.
     *
     * ⚠️ TWO KINDS, NOT FOUR, AND THE OTHER TWO ARE NOT FORGOTTEN. Approving a
     * course order enrols the student and `NotifyStudentEnrolled` says so;
     * approving a subscription activates it and `ActivateSubscription` says so
     * (or says why the seat was unavailable). A second message about the same
     * click is noise that teaches people to mute the tab. A credit purchase and a
     * store purchase had NO outcome message on the manual path at all —
     * `PaymentConfirmed` is fired by a gateway capture only — so the payer who
     * uploaded a receipt went on reading «قيد المراجعة» after it was accepted.
     */
    public function handleReceiptApproved(ReceiptApproved $event): void
    {
        $order = $event->order;

        if (! in_array($order->kind, [OrderKind::Credits, OrderKind::Store], true)) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $order->user,
            type: NotificationType::ReceiptApproved,
            variables: ['course' => $this->describe($order)],
            // Where the thing bought now lives: the purchases, or the balance.
            actionUrl: $order->kind === OrderKind::Store ? '/store' : '/billing',
            subject: $order->user,
            workspaceId: $order->workspace_id,
        ));
    }

    /**
     * A human refused the receipt — and the payer needs the reason AND the way on.
     *
     * ⚠️ `/orders`, NOT «start a new order». A rejected order takes a new receipt
     * on the SAME row (027 · FR-032): the reason, the quoted amount and the
     * subscription snapshot all live there, and a fresh order would lose all
     * three and put a second pending one beside the first. `/orders` is where the
     * row shows the reason and offers the upload again.
     *
     * Every kind, because a refusal has no other announcer.
     */
    public function handleReceiptRejected(ReceiptRejected $event): void
    {
        $order = $event->order;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $order->user,
            type: NotificationType::ReceiptRejected,
            variables: [
                'course' => $this->describe($order),
                'reason' => $event->reason,
            ],
            actionUrl: '/orders',
            subject: $order->user,
            workspaceId: $order->workspace_id,
        ));
    }

    /**
     * What the order was FOR, in words — never empty.
     *
     * ⚠️ The template REQUIRES this variable, and a required variable left empty
     * refuses the whole message (FR-037). A store or subscription order carries no
     * course, so the kind's own label stands in rather than a blank that would
     * drop the notification in silence.
     */
    private function describe(Order $order): string
    {
        $title = $order->course?->title;

        return is_string($title) && $title !== '' ? $title : $order->kind->label();
    }
}
