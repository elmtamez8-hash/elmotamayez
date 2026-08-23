<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

/**
 * The room under a session or a lesson — found, or opened on first arrival
 * (`FR-017`).
 *
 * ⚠️ THE ROOM IS CREATED BY WHOEVER OPENS IT FIRST, and that makes it the same
 * race `StartConversation` declares: two students tap the chat in the same second,
 * both find nothing, both insert. The difference is that `unique(workspace_id,
 * student_user_id)` cannot catch it — `student_user_id` is NULL for a room and
 * NULL never collides — which is why the migration adds a single-column unique on
 * `class_session_id` and on `lesson_id`. Without it there is no violation to
 * catch, and the room splits in two for ever with nothing that throws.
 *
 * ⚠️ AND ENTITLEMENT IS ASKED BEFORE THE ROW EXISTS, through the same policy the
 * door uses. A second condition written here would be the answer the reader gets
 * on the screen and not the one they get at `ReadMessages` — the divergence this
 * repository has now recorded three times.
 */
class ResolveSessionConversation extends Action
{
    public function forSession(User $actor, string $sessionUuid): Conversation
    {
        $session = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $sessionUuid)
            ->first();

        if (! $session instanceof ClassSession) {
            throw new ModelNotFoundException('لم نجد هذه الحصّة.');
        }

        return $this->resolve($actor, [
            'workspace_id' => (int) $session->workspace_id,
            'kind' => ConversationKind::Session,
            'class_session_id' => (int) $session->getKey(),
        ], 'class_session_id');
    }

    public function forLesson(User $actor, string $lessonUuid): Conversation
    {
        $lesson = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $lessonUuid)
            ->first();

        if (! $lesson instanceof Lesson) {
            throw new ModelNotFoundException('لم نجد هذا الدرس.');
        }

        return $this->resolve($actor, [
            'workspace_id' => (int) $lesson->workspace_id,
            'kind' => ConversationKind::Lesson,
            'lesson_id' => (int) $lesson->getKey(),
        ], 'lesson_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  string  $column  the one that carries the unique index
     */
    private function resolve(User $actor, array $attributes, string $column): Conversation
    {
        $existing = $this->find($column, (int) $attributes[$column]);

        if ($existing instanceof Conversation) {
            Gate::forUser($actor)->authorize('view', $existing);

            return $existing;
        }

        // Authorised against the room that does not exist yet — same class,
        // unsaved — so there is exactly one spelling of «may you be in here».
        $candidate = new Conversation($attributes);

        Gate::forUser($actor)->authorize('view', $candidate);

        try {
            $candidate->save();
        } catch (QueryException $e) {
            // The declared loser: somebody opened it a millisecond earlier. Whether
            // the row is there is the check — never a driver-specific error code.
            $winner = $this->find($column, (int) $attributes[$column]);

            if (! $winner instanceof Conversation) {
                throw $e;
            }

            return $winner;
        }

        return $candidate;
    }

    private function find(string $column, int $id): ?Conversation
    {
        return Conversation::query()
            ->withoutWorkspaceScope()
            ->where($column, $id)
            ->first();
    }
}
