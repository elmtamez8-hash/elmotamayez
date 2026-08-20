<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Gamification\Events\RewardRedeemed;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A reward was claimed and somebody owes something (FR-032).
 *
 * ⚠️ THE RECIPIENT IS THE STUDENT, AND THE GUARDIAN ARRIVES THROUGH THE TYPE, not
 * through a second dispatch here. `RecipientResolver` fans the message out to
 * every guardian holding the payments consent — which is why the type needed BOTH
 * `targetsGuardians()` and a `requiredGuardianPermission()`. With only the first,
 * the message would pick up the WhatsApp channel, be billed, and reach no
 * guardian at all.
 */
class NotifyOnRewardRedeemed implements ShouldQueue
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(RewardRedeemed $event): void
    {
        $redemption = $event->redemption;
        $student = $redemption->student;
        $reward = $redemption->reward;

        if ($student === null || $reward === null) {
            return;
        }

        $owner = $redemption->workspace?->owner;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::RewardRedeemed,
            variables: [
                'student_name' => $student->name,
                'reward_title' => $reward->title,
                // Never an empty string: TemplateRenderer counts present-but-empty
                // as MISSING and drops the whole message — for exactly the
                // workspaces whose owner record is incomplete.
                'teacher_name' => $owner === null ? 'مدرّسك' : $owner->name,
            ],
            actionUrl: '/shop',
            workspaceId: (int) $redemption->workspace_id,
        ));
    }
}
