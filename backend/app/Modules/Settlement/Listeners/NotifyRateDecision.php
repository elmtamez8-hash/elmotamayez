<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Events\SettlementRateApproved;
use App\Modules\Settlement\Support\Money;

/**
 * The teacher is told their rate moved, and from when.
 *
 * The "from when" is the point. A teacher who sees only the new number will
 * assume it applies to the hours they taught last week, and discovering
 * otherwise on the statement is the argument this whole context exists to
 * prevent — so the message says outright that earlier units keep their price.
 *
 * Through DispatchNotification and nothing else: business logic names a
 * recipient and a type, never a channel (ProviderAgnosticTest fails the build
 * otherwise).
 */
class NotifyRateDecision
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(SettlementRateApproved $event): void
    {
        $rate = $event->rate;
        $teacher = User::query()->find($rate->teacherProfile?->user_id);

        if ($teacher === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::SettlementRateApproved,
            variables: [
                'amount' => Money::format($rate->amount_minor, (string) $rate->currency),
                'session_type' => $rate->session_type->label(),
                'effective_from' => $rate->effective_from->toDateString(),
            ],
            actionUrl: '/manage/settlement',
            subject: $teacher,
            workspaceId: (int) $rate->workspace_id,
        ));
    }
}
