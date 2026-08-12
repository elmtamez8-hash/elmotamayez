<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Events\PaymentReversed;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A reversal takes money back, so what that money unlocked is reconsidered.
 *
 * ⚠️ NOTHING IS RE-DERIVED HERE, AND THAT IS WHY THERE IS SO LITTLE CODE.
 * Withholding is computed from five live inputs — balance, ceiling, mode, exam
 * window, current consent — so removing the payment IS the re-evaluation; the
 * next read of the balance already answers differently. A listener that
 * recalculated and stored a flag would be the drifting copy the design refuses.
 *
 * What it does do is tell the two people who cannot see that happen: the payer,
 * because access they had may now be blocked, and it is not their doing; and
 * nobody else automatically — a reversal is a dispute, and disputes are handled
 * by a person reading the audit log.
 */
class ReevaluateOnReversal implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(PaymentReversed $event): void
    {
        $this->dispatch->handle(new NotificationRequest(
            recipient: $event->order->user,
            type: NotificationType::PaymentReversed,
            variables: ['reason' => $event->reason ?? 'نزاع على الدفعة.'],
            workspaceId: $event->order->workspace_id,
        ));
    }
}
