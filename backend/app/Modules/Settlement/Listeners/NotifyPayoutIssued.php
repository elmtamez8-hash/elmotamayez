<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Events\TeacherPayoutIssued;
use App\Modules\Settlement\Support\Money;

/**
 * Money left, and the teacher is told with the reference that identifies it.
 *
 * The reference is the whole content of the message. "Your payout was executed"
 * with no way to match it against a bank line is a notification the teacher
 * cannot act on, and the first thing they will ask for is exactly this string.
 */
class NotifyPayoutIssued
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(TeacherPayoutIssued $event): void
    {
        $payout = $event->payout;
        $teacher = User::query()->find($payout->teacherProfile?->user_id);

        if ($teacher === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::TeacherPayoutIssued,
            variables: [
                'amount' => Money::format($payout->amount_minor, (string) $payout->currency),
                // Em dash rather than an empty string: a template refuses to
                // render a missing variable and DispatchNotification logs the
                // refusal instead of failing, so a payout with no reference would
                // silently produce no notification at all.
                'reference' => (string) ($payout->reference ?? '—'),
            ],
            actionUrl: '/manage/settlement',
            subject: $teacher,
            workspaceId: (int) $payout->workspace_id,
        ));
    }
}
