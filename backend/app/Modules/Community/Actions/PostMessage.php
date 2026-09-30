<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\PostMessageData;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Events\MessagePosted;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Community\Support\CommunitySettings;
use App\Modules\Community\Support\ConversationSides;
use App\Modules\Community\Support\ProspectAllowance;
use App\Modules\Community\Support\TermFilter;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use PDOException;
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
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly AssistantScopeDirectory $assistants,
        private readonly TermFilter $terms,
        private readonly ConversationSides $sides,
        private readonly ProspectAllowance $allowance,
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

        return $this->deliver($sender, $conversation, $data);
    }

    /**
     * Write one message into a conversation — or into one that does not exist
     * yet, which this call then creates (2026-09-28).
     *
     * ⛔ «NO CONVERSATION UNTIL THE FIRST MESSAGE» (owner decision, reported from
     * production). `StartConversation` used to insert the thread when «راسل» was
     * pressed, so an empty conversation sat in both sides' lists before anybody
     * had typed a word. Now it hands this method an UNSAVED `Conversation`, and
     * the thread, its participant row and its first message are one
     * transaction: a refusal of the words (the term list, the cap) leaves
     * nothing behind at all.
     *
     * ⚠️ A UNIQUE VIOLATION ON THE THREAD IS RETHROWN, NOT HANDLED HERE. Two
     * devices sending a first message in the same second both try to insert;
     * the loser's whole transaction — message included — rolls back, and
     * `StartConversation` re-reads the winner and delivers into it. Catching it
     * here would mean deciding which thread a message belongs to from inside
     * the transaction that just failed.
     */
    public function deliver(User $sender, Conversation $conversation, PostMessageData $data): Message
    {
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

        $isPrivate = $conversation->kind === ConversationKind::Private;
        $fromStudentSide = $isPrivate && $this->sides->speaksForStudent($sender, $conversation);

        /*
        | Asked before the transaction: whether the student studies here does not
        | change because of what this transaction does, and asking it inside would
        | hold the row lock across an enrolment read for nothing. A thread that
        | does not exist yet holds no message, so its first one is always inside
        | the budget — its gate is the unique index, not a count.
        */
        $capped = $fromStudentSide && $conversation->exists && $this->allowance->isProspect($conversation);
        $epoch = $capped ? $this->allowance->epoch($conversation) : null;

        try {
            $message = DB::transaction(
                fn (): Message => $this->write($conversation, $sender, $data, $attachment, $capped, $epoch, $isPrivate && ! $fromStudentSide),
                // ⚠️ ONE ATTEMPT FOR A NEW THREAD. A retry would find the model
                // already marked `exists` by the rolled-back insert and write a
                // message against a conversation id that no longer exists.
                attempts: $conversation->exists ? 3 : 1,
            );
        } catch (PDOException $e) {
            // A deadlock or a lock wait the retries could not clear is neither the
            // cap nor the sender's fault — and never a 500. `QueryException` is a
            // `PDOException`, so the unique violation `StartConversation` handles
            // passes through here untouched.
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw new DomainException('تعذّر إرسال الرسالة الآن. حاول مرة أخرى.');
        }

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
     * The body of {@see deliver()}'s transaction.
     *
     * ⚠️ FOR A CAPPED SENDER THE GATE IS THE FIRST STATEMENT, AND NOTHING IS READ
     * ABOVE IT. `UPDATE conversations SET id = id` changes nothing and still takes
     * the row's exclusive lock until commit, so two sends a millisecond apart
     * queue on it and the second one COUNTS the first — the `CreateFreezePeriod`
     * idiom. Never `lockForUpdate()`, which is a no-op on SQLite, and never
     * `INSERT … WHERE (SELECT COUNT(*)) < ?`, which is not atomic on MySQL.
     * The teacher's answer is looked for after the gate for the same reason: a
     * reply committed while this send waited lifts the cap for it. Both are
     * counted from the EPOCH — when the student became a prospect — see
     * `ProspectAllowance`.
     */
    private function write(
        Conversation $conversation,
        User $sender,
        PostMessageData $data,
        ?MediaAsset $attachment,
        bool $capped,
        ?CarbonImmutable $epoch,
        bool $fromStaff,
    ): Message {
        if (! $conversation->exists) {
            // The thread and its student's participant row, then the message —
            // one write. See `StartConversation` for why only the student
            // gets a participant row.
            $conversation->save();

            ConversationParticipant::query()->create([
                'conversation_id' => $conversation->getKey(),
                'user_id' => $conversation->student_user_id,
            ]);
        } elseif ($capped) {
            DB::update('UPDATE conversations SET id = id WHERE id = ?', [$conversation->getKey()]);

            if ($this->allowance->exhausted((int) $conversation->getKey(), $epoch)) {
                throw new DomainException(ProspectAllowance::refusal(CommunitySettings::prospectMessageCap()));
            }
        }

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
            // The teacher sees WHO on the student's side wrote this line.
            // Which side wrote it — the prospect cap lifts on a teacher-side line.
            'from_staff' => $fromStaff,
            'sent_by_guardian' => ! $fromStaff
                && $conversation->kind === ConversationKind::Private
                && (int) $sender->getKey() !== (int) $conversation->student_user_id,
        ]);

        $this->claimLastMessage($conversation, (int) $message->getKey());

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

    /**
     * The members who sit on the TEACHING side of this private thread — the
     * people `ConversationPolicy::teacherSide()` lets in as staff: a member of
     * the workspace, holding `chat.reply` there, whom the assistant scope lets
     * act on this student.
     *
     * ⛔ UNTIL 2026-09-30 THIS WAS «EVERY MEMBER WHOM `mayActOnStudent()` LETS
     * THROUGH», and that directory answers `true` for anybody who is not a
     * confined assistant — so every STUDENT-role member (a fixture's
     * `addWorkspaceMember(STUDENT)`, and in production a student a teacher once
     * invited) and every staff member WITHOUT chat access was told, by name and
     * with a link, each time this student wrote. The link opens nothing for
     * them; the notification alone is the leak.
     *
     * ⚠️ BATCHED, AND THE ORDER IS FOR THE QUERY COUNT: one read for the staff
     * pivot, one for the permission (spatie's `permission()` scope, which reads
     * roles AND direct grants under the team id `forWorkspace()` pins — the
     * sender's own context may be another workspace or none), then the
     * directory's one batched call. The loop it replaced cost an assignment read
     * per member on every message.
     *
     * ⚠️ `wherePivot('role', '!=', STUDENT)` IS NOT REDUNDANT WITH THE
     * PERMISSION. A student-role member holds no `chat.reply` by default, but a
     * direct grant or a role edit is one tick away, and the pivot is what says
     * which side of the thread they are on.
     *
     * @return list<string>
     */
    private function staffSideOf(Workspace $workspace, Conversation $conversation): array
    {
        $workspaceId = (int) $conversation->workspace_id;

        $staffIds = $workspace->members()
            ->wherePivot('role', '!=', Roles::STUDENT)
            ->pluck('users.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($staffIds === []) {
            return [];
        }

        /** @var array<int, string> $withChat user id => uuid */
        $withChat = app(WorkspaceContext::class)->forWorkspace($workspaceId, fn (): array => User::query()
            ->whereIn('users.id', $staffIds)
            ->permission(Permissions::CHAT_REPLY)
            ->orderBy('users.id')
            ->pluck('users.uuid', 'users.id')
            ->map(fn (mixed $uuid): string => (string) $uuid)
            ->all());

        $allowed = $this->assistants->whoMayActOnStudent(
            array_map(intval(...), array_keys($withChat)),
            $workspaceId,
            (int) $conversation->student_user_id,
        );

        return array_map(fn (int $id): string => $withChat[$id], $allowed);
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
            | what `mayActOnStudent()` exists to withhold — and that predicate
            | alone was never the staff side: see `staffSideOf()`.
            */
            foreach ($this->staffSideOf($workspace, $conversation) as $uuid) {
                $uuids[] = $uuid;
            }
        }

        /*
        | ⚠️ A GUARDIAN WHO HAS WRITTEN HERE IS TOLD OF THE ANSWER, AND ONE WHO
        | HAS NOT IS NOT (2026-09-28). A guardian has no participant row — the
        | thread is the child's, and their right to it is the relation, checked
        | on every read — so without this the parent who asked the question
        | never learns it was answered. Pushing every line of a child's
        | correspondence to every authorised adult, unasked, is a different
        | product, so it is only the guardians who took part.
        */
        $guardianIds = $this->sides->guardianIdsFor($conversation);

        if ($guardianIds !== []) {
            $spoke = Message::query()
                ->withoutWorkspaceScope()
                ->where('conversation_id', $conversation->getKey())
                ->whereIn('sender_user_id', $guardianIds)
                ->distinct()
                ->pluck('sender_user_id')
                ->all();

            if ($spoke !== []) {
                foreach (User::query()->whereIn('id', $spoke)->pluck('uuid') as $uuid) {
                    $uuids[] = (string) $uuid;
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
