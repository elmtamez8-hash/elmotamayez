<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\PostMessageData;
use App\Modules\Community\Events\MessagePosted;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write one message and tell whoever is listening that it exists.
 *
 * ⚠️ THE ROW IS THE MESSAGE AND THE SOCKET IS AN ACCELERATOR (`SC-015`). The
 * response carries the saved message, so a client that never receives a single
 * frame still shows what it just sent and finds it again on reload. Which is why
 * the broadcast is wrapped: on the `sync` queue — and, in production, if the
 * queue itself is unreachable at dispatch time — a publish failure would
 * otherwise propagate into the request and turn «reverb is down» into «your
 * message was not sent», with the row already written.
 */
class PostMessage extends Action
{
    public function handle(User $sender, PostMessageData $data): Message
    {
        $conversation = Conversation::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $data->conversationUuid)
            ->first();

        if (! $conversation instanceof Conversation) {
            throw new ModelNotFoundException('لم نجد هذه المحادثة.');
        }

        Gate::forUser($sender)->authorize('post', $conversation);

        $message = DB::transaction(function () use ($conversation, $sender, $data): Message {
            $message = Message::query()->create([
                // ⚠️ FROM THE CONVERSATION, NEVER FROM THE CONTEXT. The sender is
                // usually a student, who is a member of no workspace at all — so
                // the trait's auto-fill would write null, or for a teacher signed
                // into a second workspace, the wrong one.
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->getKey(),
                'sender_user_id' => $sender->getKey(),
                'body' => $data->body,
            ]);

            $this->claimLastMessage($conversation, (int) $message->getKey());

            return $message;
        });

        $this->announce($conversation, $message, $sender);

        return $message;
    }

    /**
     * Move the conversation's pointer to this message — if this message is newer.
     *
     * ⚠️ A CONDITIONAL UPDATE, NOT AN ASSIGNMENT. Two writers a millisecond apart
     * can finish in either order, and the one that finishes last may hold the
     * SMALLER id: assigned, the conversation is then sorted for ever by a message
     * that is not its most recent one, and the list shows a stale preview nothing
     * ever corrects. `WHERE last_message_id IS NULL OR last_message_id < ?` is
     * both the check and the write — the seat idiom, and never `lockForUpdate()`,
     * which is a no-op on SQLite.
     *
     * Public because `MessageOrderingTest` plays the losing order out against the
     * shipped statement rather than against a fixture of its own.
     */
    public function claimLastMessage(Conversation $conversation, int $messageId): void
    {
        Conversation::query()
            ->withoutWorkspaceScope()
            ->whereKey($conversation->getKey())
            ->where(function ($query) use ($messageId): void {
                $query->whereNull('last_message_id')->orWhere('last_message_id', '<', $messageId);
            })
            ->update(['last_message_id' => $messageId]);
    }

    /**
     * Tell the open thread and everyone's list.
     *
     * The recipient list is computed here, where the conversation is already
     * loaded, and travels ON the event: a listener that read it afterwards would
     * be one revocation away from a different answer, and `NotifyOfflineRecipient`
     * would then need Tenancy's models to work it out for itself.
     */
    private function announce(Conversation $conversation, Message $message, User $sender): void
    {
        $recipients = $this->recipientUuids($conversation, $sender);

        try {
            event(new MessagePosted($message, (string) $conversation->uuid, $recipients));
        } catch (Throwable $e) {
            /*
            | ⚠️ THE ROW SURVIVES THE OUTAGE, AND THAT IS THE REQUIREMENT
            | (`NFR-013` · `SC-015`). Reported rather than swallowed — a silent
            | catch is how a broadcast layer stays broken for a week — but never
            | rethrown: the message is already committed, and failing the request
            | now would ask the student to send it again and write it twice.
            */
            // ⚠️ THE KEY SAYS «announce», NOT «broadcast». Laravel dispatches the
            // broadcast BEFORE the listeners, so this one catch covers both the
            // publish and `NotifyOfflineRecipient` behind it — a key naming only
            // the socket would send the next reader looking in the wrong place.
            Log::warning('community.message_announce_failed', [
                'exception' => $e::class,
                'conversation_uuid' => (string) $conversation->uuid,
            ]);
        }
    }

    /** @return list<string> */
    private function recipientUuids(Conversation $conversation, User $sender): array
    {
        $uuids = ConversationParticipant::query()
            ->where('conversation_id', $conversation->getKey())
            ->join('users', 'users.id', '=', 'conversation_participants.user_id')
            ->pluck('users.uuid')
            ->all();

        $workspace = Workspace::query()->find($conversation->workspace_id);

        if ($workspace instanceof Workspace) {
            // The teacher's side has no participant rows — see `StartConversation`
            // — so the members are asked for directly.
            $uuids = array_merge($uuids, $workspace->members()->pluck('users.uuid')->all());
        }

        $senderUuid = (string) $sender->uuid;

        $recipients = [];

        foreach (array_unique($uuids) as $uuid) {
            if ((string) $uuid !== $senderUuid) {
                $recipients[] = (string) $uuid;
            }
        }

        return $recipients;
    }
}
