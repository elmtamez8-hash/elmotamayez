<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\PostMessageData;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Events\MessagePosted;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Community\Support\TermFilter;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AssistantScopeDirectory;
use DomainException;
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
    public function __construct(
        private readonly AssistantScopeDirectory $assistants,
        private readonly TermFilter $terms,
    ) {}

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

        /*
        | The term list (`FR-020`), applied AFTER the policy and before the write.
        |
        | ⚠️ A REFUSAL IS A 422 AND NOT A 403, because it is about the words and
        | not about the person: `DomainException` is the repository's signal for a
        | broken business rule, and rendering it as a permission failure would tell
        | a student they may not write here when they may.
        */
        $filtered = $this->terms->apply((int) $conversation->workspace_id, $data->body);

        if ($filtered['refused'] !== null) {
            throw new DomainException($filtered['refused']);
        }

        $data = new PostMessageData($data->conversationUuid, $filtered['body'], $data->attachmentUuid);

        $attachment = $this->resolveAttachment($conversation, $data->attachmentUuid);

        /*
        | ⚠️ «TEXT OR ATTACHMENT, NEVER NEITHER» IS ENFORCED HERE, because `body`
        | is nullable in the schema now. A voice note has no words, so a NOT NULL
        | column would have forced `''` and made «empty message» and «recording»
        | the same row. The rule that replaces the constraint belongs in the
        | Action — the single entry point the seeders, the panel and the API all
        | share — exactly as every other business rule on this model does.
        */
        if ($data->body === '' && $attachment === null) {
            throw new DomainException('اكتب رسالة أو أرفق ملفاً.');
        }

        $message = DB::transaction(function () use ($conversation, $sender, $data, $attachment): Message {
            $message = Message::query()->create([
                // ⚠️ FROM THE CONVERSATION, NEVER FROM THE CONTEXT. The sender is
                // usually a student, who is a member of no workspace at all — so
                // the trait's auto-fill would write null, or for a teacher signed
                // into a second workspace, the wrong one.
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->getKey(),
                'sender_user_id' => $sender->getKey(),
                // Null rather than '' when there is only an attachment: the
                // column is nullable precisely so the two cases stay distinct.
                'body' => $data->body === '' ? null : $data->body,
                'media_asset_id' => $attachment?->getKey(),
            ]);

            $this->claimLastMessage($conversation, (int) $message->getKey());

            return $message;
        });

        foreach ($filtered['review'] as $term) {
            /*
            | Delivered, and a human is told (`TermPolicy::Review`). The row is
            | raised AFTER the message exists, because it points at it — and the
            | actor is null: nobody decided anything yet, the filter noticed.
            |
            | ⚠️ THE `reason` NAMES THE MATCHED TERM, AND IT IS MODERATOR-EYES-ONLY
            | BY THE ABSENCE OF ANY OTHER READER. `TermFilter` refuses to echo a
            | term back to a sender on purpose — a list somebody can enumerate is a
            | list they can spell around — and this row is created by the sender's
            | own request. It is safe today because nothing sender-facing reads
            | `moderation_actions`: the report endpoint answers a constant 202 and
            | there is no listing outside the moderator's own. Build a «my reports»
            | screen and this line is the leak; drop the term from the reason
            | first.
            */
            ModerationAction::query()->create([
                'workspace_id' => $conversation->workspace_id,
                'actor_user_id' => null,
                'subject_type' => ModerationAction::SUBJECT_MESSAGE,
                'subject_id' => $message->getKey(),
                'verdict' => ModerationVerdict::Reported,
                'reason' => 'كلمة تحت المراجعة: '.$term,
            ]);
        }

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
        /*
        | ⚠️ A PUBLIC ROOM TELLS NOBODY, AND THE SILENCE IS DELIBERATE. Fanning a
        | class chat out the way a private conversation is fanned out would write
        | one bell row per member per message and publish to one `user.{uuid}`
        | channel per participant — thirty channels for one «تمام» in a full room,
        | and a notification feed nobody can read by the end of a lesson. The
        | conversation channel is the whole delivery; anyone not in the room finds
        | the messages when they open it. Nothing in US3 asks for more.
        */
        $recipients = $conversation->kind->isPublic()
            ? []
            : $this->recipientUuids($conversation, $sender);

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
            // ⚠️ THE KEY SAYS «announce», NOT «broadcast», AND THIS CATCH DOES NOT
            // SEE A REVERB OUTAGE — measured on 2026-08-23 with the server killed
            // mid-session. `MessagePosted` is `ShouldBroadcast`, not
            // `ShouldBroadcastNow`, and `NotifyOfflineRecipient` is queued too, so
            // `event()` only ENQUEUES here and returns; the publish happens on the
            // worker, where this block is long gone. What it actually guards is a
            // synchronous failure while the event is being built or serialised —
            // rare, and still worth not failing a committed message over.
            //
            // The outage itself lands in `failed_jobs` with the queue's own
            // retries, which is the correct place for it: `SC-015` is about the
            // ROW surviving and the reader being one refresh behind, and both hold
            // — the send succeeded with the socket down and the API returned the
            // message. Do not "fix" this by swallowing the job's failure; an
            // undelivered broadcast that nothing records is the week-long silent
            // breakage this catch was written against in the first place.
            Log::warning('community.message_announce_failed', [
                'exception' => $e::class,
                'conversation_uuid' => (string) $conversation->uuid,
            ]);
        }
    }

    /**
     * The asset this message is about to carry, or null.
     *
     * ⚠️ THREE CONDITIONS, AND EACH ONE IS A REAL ATTACK RATHER THAN A FORMALITY.
     * The uuid arrives in a request body, so without them a sender could name
     * ANY asset on the platform and have it rendered inside their own thread:
     *
     *  - it must be owned by THIS conversation — an asset uuid is otherwise a
     *    read primitive for every lesson video and every other thread's pictures;
     *  - it must be `Ready` — a `Pending` row is an upload that never arrived, and
     *    attaching it puts a permanently broken image in a thread nobody can edit;
     *  - it must not already be on a message — one ticket, one message. Without
     *    this, a second send re-uses the first upload and the moderation archive
     *    describes two messages by one file, so hiding one leaves the other.
     *
     * A failure is a `DomainException` and not a 403: the sender chose a file, and
     * telling them «you may not write here» about a picture they just uploaded is
     * the wrong sentence.
     */
    private function resolveAttachment(Conversation $conversation, ?string $uuid): ?MediaAsset
    {
        if ($uuid === null) {
            return null;
        }

        $asset = MediaAsset::query()
            // A student is a member of no workspace, so the global scope adds no
            // condition for them — the ownership check below is the real guard
            // and is written out rather than relied upon.
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->where('owner_type', Conversation::class)
            ->where('owner_id', $conversation->getKey())
            ->first();

        if (! $asset instanceof MediaAsset) {
            throw new DomainException('لم نجد المرفق. أعد رفعه.');
        }

        if ($asset->status !== MediaAssetStatus::Ready) {
            throw new DomainException('لم يكتمل رفع المرفق بعد.');
        }

        $claimed = Message::query()
            ->withoutWorkspaceScope()
            ->where('media_asset_id', $asset->getKey())
            ->exists();

        if ($claimed) {
            throw new DomainException('هذا المرفق مُرسَل بالفعل.');
        }

        return $asset;
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
            /*
            | The teacher's side has no participant rows — see `StartConversation`
            | — so the members are asked for directly.
            |
            | ⚠️ AND NARROWED BY THE DOOR'S OWN PREDICATE. An unfiltered member
            | list tells EVERY assistant in the workspace, by name, every time
            | this student writes — including one confined to courses the student
            | is not in, who cannot open the thread at all. The confinement would
            | still hold at the door and be defeated at lower resolution: the
            | notification carries the sender's name and a link, which is most of
            | what `mayActOnStudent()` exists to withhold. Free for a teacher or
            | an owner, who are not confined and answer from a memo.
            */
            $workspaceId = (int) $conversation->workspace_id;
            $studentId = (int) $conversation->student_user_id;

            foreach ($workspace->members()->get() as $member) {
                if ($this->assistants->mayActOnStudent($member, $workspaceId, $studentId)) {
                    $uuids[] = (string) $member->uuid;
                }
            }
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
