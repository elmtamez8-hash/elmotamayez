<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Events\AccessRestored;
use App\Modules\Payments\Events\AccessWithheld;
use App\Modules\Payments\Models\CreditBalance;
use App\Shared\Contracts\AccountStanding;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Told they are blocked, and told when they are not.
 *
 * One listener for both events rather than two, because the pair is a single
 * fact stated twice: the predicate flipped. Split across two files, the second
 * one written is the one that forgets the guardian, and a family learns about
 * the block and never about the lift.
 *
 * Both types are MANDATORY (FR-035). A preference that could hide either would
 * leave someone locked out with no way to find out why, and then let back in
 * without knowing they may return — which is not a notification setting, it is a
 * person unable to use what they paid for.
 *
 * The restore needs no action of its own. Withholding is derived, so the student
 * is already unblocked on their next attempt; this exists so they do not have to
 * discover it by being refused one more time (FR-033).
 */
class NotifyAccessChange implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $notifications,
        private readonly AccountStanding $standing,
    ) {}

    public function handleWithheld(AccessWithheld $event): void
    {
        $this->notify($event->balance, NotificationType::AccessWithheld);
    }

    public function handleRestored(AccessRestored $event): void
    {
        $this->notify($event->balance, NotificationType::AccessRestored);
    }

    private function notify(CreditBalance $balance, NotificationType $type): void
    {
        $student = User::query()->find($balance->student_user_id);

        if ($student === null) {
            return;
        }

        $variables = ['course' => (string) $balance->course->title];

        // Each template declares exactly the variables its body reads, and a
        // missing one refuses the render rather than printing a gap (FR-037). So
        // the two bodies take different keys and this is not a shared payload
        // with an unused half.
        $variables += $type === NotificationType::AccessWithheld
            ? ['credits_needed' => (string) $this->standing->creditsNeededFor($student, (int) $balance->course_id)]
            : ['credits' => (string) max(0, $balance->remaining_credits)];

        $this->notifications->handle(new NotificationRequest(
            recipient: $student,
            type: $type,
            variables: $variables,
            actionUrl: '/billing',
            subject: $student,
            workspaceId: (int) $balance->workspace_id,
        ));
    }
}
