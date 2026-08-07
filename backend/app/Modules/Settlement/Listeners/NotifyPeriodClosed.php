<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Events\SettlementPeriodClosed;
use App\Modules\Settlement\Support\Money;

/**
 * The teacher learns their period closed, and at what.
 *
 * FR-029. A total that stops moving without anyone saying so is a total the
 * teacher discovers when the money is either there or not — and by then the
 * question is an argument rather than a query.
 *
 * Through DispatchNotification and nothing else: business logic names a
 * recipient and a type, never a channel (ProviderAgnosticTest fails the build
 * otherwise).
 */
class NotifyPeriodClosed
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(SettlementPeriodClosed $event): void
    {
        $period = $event->period;
        $teacher = User::query()->find($period->teacherProfile?->user_id);

        if ($teacher === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::SettlementPeriodClosed,
            variables: [
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'units_count' => (string) $period->units_count,
                // The frozen figure, not a recomputed one: the message and the
                // statement have to agree, and by now the row is the answer.
                'net' => Money::format($period->net_minor, (string) $period->currency),
            ],
            actionUrl: '/manage/settlement',
            subject: $teacher,
            workspaceId: (int) $period->workspace_id,
        ));
    }
}
