<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Events\BalanceThresholdCrossed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The ladder: a quiet word to the student, then the person who pays.
 *
 * Tier 1 is the student alone — `CreditBalanceLow` reaches no guardian, by its
 * own `targetsGuardians()`. Tier 2 and deeper is `CreditBalanceCritical`, which
 * does, gated on the Payments consent specifically. That escalation IS FR-030,
 * and it lives in this one mapping rather than in a condition at each dispatch
 * site.
 *
 * Nothing here names a channel. The recipient's preferences choose that, and
 * ProviderAgnosticTest fails the build if a channel ever appears under Actions/.
 *
 * Repetition is not guarded here either, and must not be: the crossing was
 * claimed with a conditional UPDATE inside the transaction that moved the
 * balance, so an event that reaches this listener is by construction a crossing
 * nobody has been told about. A second check here would be a second definition
 * of "already announced", and the two would disagree first under exactly the
 * concurrency the first one exists for.
 */
class NotifyBalanceThreshold implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $notifications,
    ) {}

    public function handle(BalanceThresholdCrossed $event): void
    {
        $balance = $event->balance;
        $student = User::query()->find($balance->student_user_id);

        if ($student === null) {
            return;
        }

        $this->notifications->handle(new NotificationRequest(
            recipient: $student,
            type: $event->tier >= 2
                ? NotificationType::CreditBalanceCritical
                : NotificationType::CreditBalanceLow,
            variables: [
                'course' => (string) $balance->course->title,
                // The credits, never a price. What the student holds is sessions;
                // the riyals live on the purchase screen, where the platform sets
                // them and where a teacher's rate cannot be read back out of one.
                'credits' => (string) max(0, $balance->remaining_credits),
            ],
            actionUrl: '/billing',
            subject: $student,
            workspaceId: (int) $balance->workspace_id,
        ));
    }
}
