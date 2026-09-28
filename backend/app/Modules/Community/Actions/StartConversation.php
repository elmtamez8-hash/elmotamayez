<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\PostMessageData;
use App\Modules\Community\Data\StartConversationData;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

/**
 * Send the first message to a teacher's side — opening the one private
 * conversation between that workspace and a student if it does not exist yet,
 * or writing into it if it does.
 *
 * ⛔ THE THREAD IS BORN WITH ITS FIRST MESSAGE, NEVER BEFORE (owner decision
 * 2026-09-28, reported from production). This Action used to insert the row the
 * moment «راسل» was pressed, so an EMPTY conversation stood in both sides' lists
 * before anybody had typed a word. Pressing the button now opens a compose view;
 * this runs on «إرسال», and the conversation, its participant row and the
 * message are one transaction inside `PostMessage::deliver()`.
 *
 * ⚠️ THE RACE IS DECLARED RATHER THAN HOPED AGAINST. Two devices send a first
 * message in the same second: both look, both find nothing, both insert, and one
 * of them hits `unique(workspace_id, student_user_id)`. Its transaction rolls
 * back — its message with it — so the losing path re-reads the winner and
 * delivers the SAME words into it. Nobody's first message is lost and there is
 * still one thread. `BookSeat`'s idiom.
 *
 * ⚠️ AND EVERY IDENTIFIER IS RESOLVED HERE, INSIDE THE ACTION, AFTER NOTHING. A
 * student is a member of no workspace, so `WorkspaceScope` adds no condition for
 * them and an implicit binding would resolve any workspace's row before a policy
 * ran. Who may write — the student, their authorised guardian, the teacher's side
 * — is `ConversationPolicy::post()`, asked by `deliver()` on the existing row or
 * on the unsaved candidate alike: one spelling of «may these two talk».
 */
class StartConversation extends Action
{
    /** How long a word-for-word repeat of the newest line counts as a retry. */
    private const RESEND_WINDOW_SECONDS = 60;

    public function __construct(private readonly PostMessage $post) {}

    public function handle(User $actor, StartConversationData $data): Conversation
    {
        $workspace = Workspace::query()->where('uuid', $data->workspaceUuid)->first();

        if (! $workspace instanceof Workspace) {
            throw new ModelNotFoundException('لم نجد هذا المدرّس.');
        }

        $student = $this->resolveStudent($actor, $data);
        $message = new PostMessageData('', $data->body);

        $existing = $this->find((int) $workspace->getKey(), (int) $student->getKey());

        if ($existing instanceof Conversation) {
            if ($this->isResend($actor, $existing, $message->body)) {
                return $existing;
            }

            $this->post->deliver($actor, $existing, $message);

            return $existing->refresh();
        }

        $candidate = new Conversation([
            'workspace_id' => $workspace->getKey(),
            'kind' => ConversationKind::Private,
            'student_user_id' => $student->getKey(),
        ]);

        try {
            $this->post->deliver($actor, $candidate, $message);
        } catch (QueryException $e) {
            // The loser. The row exists because somebody else just wrote it, so
            // re-read rather than deciding from a driver-specific error code —
            // whether the row is there is the check, and it is engine-agnostic.
            $winner = $this->find((int) $workspace->getKey(), (int) $student->getKey());

            if (! $winner instanceof Conversation) {
                throw $e;
            }

            $this->post->deliver($actor, $winner, $message);

            return $winner->refresh();
        }

        return $candidate->refresh();
    }

    /**
     * The same first message, from the same person, seconds after it was saved.
     *
     * ⛔ A DROPPED RESPONSE IS NOT A SECOND MESSAGE (security review of #276). The
     * compose view sends once; if the answer is lost on a bad connection the
     * person presses «إرسال» again and, without this, the teacher reads the
     * question twice — and a prospect spends two of their three messages on one.
     * The thread's newest line being theirs, word for word, inside the window is
     * the retry; the thread is returned as it stands and nothing is written.
     * Chosen over a client key because it needs no column and no client state
     * that a reload would lose.
     */
    private function isResend(User $actor, Conversation $conversation, string $body): bool
    {
        if ($body === '' || $conversation->last_message_id === null) {
            return false;
        }

        $last = Message::query()
            ->withoutWorkspaceScope()
            ->whereKey($conversation->last_message_id)
            ->first();

        return $last instanceof Message
            && (int) $last->sender_user_id === (int) $actor->getKey()
            && (string) $last->body === $body
            && $last->created_at !== null
            && $last->created_at->greaterThanOrEqualTo(now()->subSeconds(self::RESEND_WINDOW_SECONDS));
    }

    /** The one private thread between this workspace and this student, if any. */
    public function find(int $workspaceId, int $studentId): ?Conversation
    {
        return Conversation::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $studentId)
            ->where('kind', ConversationKind::Private->value)
            ->first();
    }

    private function resolveStudent(User $actor, StartConversationData $data): User
    {
        if ($data->studentUuid === null) {
            return $actor;
        }

        $student = User::query()->where('uuid', $data->studentUuid)->first();

        if (! $student instanceof User) {
            throw new ModelNotFoundException('لم نجد هذا الطالب.');
        }

        return $student;
    }
}
