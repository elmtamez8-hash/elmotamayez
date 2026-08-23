<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Models\User;
use App\Modules\Community\Events\MessagePosted;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Somebody wrote and the other party was not watching (`FR-012`).
 *
 * ⚠️ IT NAMES A RECIPIENT AND A TYPE, NEVER A CHANNEL. Whether this reaches a
 * bell, a phone or nothing at all is `NotificationType::defaultChannels()` and
 * the recipient's own preferences — `ProviderAgnosticTest` fails the build over a
 * channel named under `Actions/`, and the rule holds here for the same reason.
 *
 * ⚠️ AND IT SENDS TO EVERYONE, RATHER THAN GUESSING WHO IS CONNECTED. There is no
 * cheap, truthful answer to «is this person looking right now» — a socket that
 * has not yet timed out is not a person at a screen. So the notification is
 * written for every other party and the bell is idempotent from the reader's
 * point of view: someone who was watching has already read the message and finds
 * the row marked in the same visit.
 */
class NotifyOfflineRecipient implements ShouldQueue
{
    public function __construct(private readonly DispatchNotification $notifications) {}

    public function handle(MessagePosted $event): void
    {
        $sender = User::query()->find($event->message->sender_user_id);

        if (! $sender instanceof User) {
            return;
        }

        // The recipients travelled ON the event, resolved while the conversation
        // was loaded — a listener that worked them out for itself would need
        // Tenancy's models here, and would read a membership list that may have
        // changed since the message was written.
        $recipients = User::query()->whereIn('uuid', $event->recipientUuids)->get();

        foreach ($recipients as $recipient) {
            $this->notifications->handle(new NotificationRequest(
                recipient: $recipient,
                type: NotificationType::ChatMessage,
                variables: ['sender_name' => $sender->name],
                actionUrl: '/messages/'.$event->conversationUuid,
                workspaceId: (int) $event->message->workspace_id,
            ));
        }
    }
}
