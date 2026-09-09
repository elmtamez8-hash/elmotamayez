<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\LiveSessions\Events\SessionRescheduleDecided;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

/**
 * The answer — and the two arms have different AUDIENCES, not just different words.
 *
 * ⚠️ AN APPROVAL REACHES EVERY SEAT HOLDER, INCLUDING THE ONE WHO ASKED. Their
 * classmates never asked for anything and their Saturday has moved; a message to
 * the requester alone leaves nine students turning up to an empty room. «قُبل
 * طلبك» is not a separate message from «تغيّر الموعد» — the new hour IS the
 * approval, and one message says both.
 *
 * ⚠️ A REFUSAL REACHES THE REQUESTER ALONE. Nothing moved, so telling the group
 * that somebody asked to shift their lesson and was turned down is a conversation
 * none of them were in.
 *
 * ⚠️ AND THE OLD TIME COMES FROM THE REQUEST, NEVER FROM THE SESSION. By the
 * time this runs the row already carries the new `starts_at`.
 */
class NotifySessionRescheduleDecided implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(SessionRescheduleDecided $event): void
    {
        $request = $event->request;
        $session = $request->classSession;

        if ($session === null) {
            return;
        }

        $from = $this->local($request->from_starts_at);

        if (! $event->approved) {
            $student = $request->student;

            if ($student === null) {
                return;
            }

            $this->dispatch->handle(new NotificationRequest(
                recipient: $student,
                type: NotificationType::SessionRescheduleRejected,
                variables: [
                    'title' => $session->title,
                    'from_time' => $from,
                    // Never empty: the renderer refuses a blank variable, and a
                    // refusal with no reason is the message that generates the
                    // support ticket it was meant to prevent. The Action demands
                    // one before it writes, so this fallback should be
                    // unreachable — and is here because «should be» is not a
                    // guarantee about a column.
                    'decision_reason' => $request->decision_reason ?? 'لم يُذكر سبب.',
                ],
                actionUrl: '/schedule',
                subject: $student,
                workspaceId: (int) $request->workspace_id,
            ));

            return;
        }

        $to = $this->local($request->to_starts_at);

        // The audience comes from the EVENT, not from a query here: a second
        // spelling of «who is in this lesson» beside `seatHolderUserIds()` is
        // two answers to one question, which this tree has paid for repeatedly.
        foreach (User::query()->whereIn('id', $event->seatHolderIds)->get() as $student) {
            $this->dispatch->handle(new NotificationRequest(
                recipient: $student,
                type: NotificationType::SessionRescheduled,
                variables: [
                    'title' => $session->title,
                    'from_time' => $from,
                    'to_time' => $to,
                ],
                actionUrl: '/schedule',
                subject: $student,
                workspaceId: (int) $request->workspace_id,
            ));
        }
    }

    private function local(Carbon $at): string
    {
        return $at->copy()->setTimezone($this->settings->timezone())->format('Y-m-d H:i');
    }
}
