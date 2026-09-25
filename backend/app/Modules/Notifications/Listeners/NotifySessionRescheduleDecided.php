<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\LiveSessions\Events\SessionRescheduleDecided;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
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
class NotifySessionRescheduleDecided implements ShouldQueueAfterCommit
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

        if (! $event->approved) {
            $student = $request->student;

            if ($student === null) {
                return;
            }

            $from = $this->local($request->from_starts_at, $student);

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

        // The audience comes from the EVENT, not from a query here: a second
        // spelling of «who is in this lesson» beside `seatHolderUserIds()` is
        // two answers to one question, which this tree has paid for repeatedly.
        foreach (User::query()->whereIn('id', $event->seatHolderIds)->get() as $student) {
            $this->dispatch->handle(new NotificationRequest(
                recipient: $student,
                type: NotificationType::SessionRescheduled,
                variables: [
                    'title' => $session->title,
                    // Per holder: two holders of one lesson may be in two zones.
                    'from_time' => $this->local($request->from_starts_at, $student),
                    'to_time' => $this->local($request->to_starts_at, $student),
                ],
                actionUrl: '/schedule',
                subject: $student,
                workspaceId: (int) $request->workspace_id,
            ));
        }
    }

    /** The reader's own clock, zone named — never the platform's (2026-09-25). */
    private function local(Carbon $at, User $reader): string
    {
        return $this->settings->formatFor($reader, $at);
    }
}
