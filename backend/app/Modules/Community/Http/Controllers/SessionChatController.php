<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\MarkHelpful;
use App\Modules\Community\Actions\ResolveCohortConversation;
use App\Modules\Community\Actions\ResolveSessionConversation;
use App\Modules\Community\Actions\SetConversationLock;
use App\Modules\Community\Actions\SetConversationWriteBan;
use App\Modules\Community\Http\Resources\ConversationResource;
use App\Modules\Community\Http\Resources\MessageResource;
use App\Modules\Community\Models\Conversation;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The room under a session or a lesson, and the teacher's endorsement.
 *
 * ⚠️ RESOLVING IS A `GET` THAT MAY WRITE, and that is deliberate. The room is
 * created by whoever opens it first; asking the client to `POST` a room into
 * existence before it can read one would mean every reader needing write
 * permission on the workspace, and a second endpoint to forget to call. The write
 * is idempotent by a unique index — see `ResolveSessionConversation`.
 */
class SessionChatController extends Controller
{
    public function session(Request $request, string $session, ResolveSessionConversation $action): JsonResponse
    {
        return $this->room($action->forSession($this->currentUser($request), $session));
    }

    public function lesson(Request $request, string $lesson, ResolveSessionConversation $action): JsonResponse
    {
        return $this->room($action->forLesson($this->currentUser($request), $lesson));
    }

    /**
     * The group's thread (021 · FR-046).
     *
     * A bare uuid and no implicit binding: every member of a cohort is a student,
     * and a student is a member of no workspace — so `WorkspaceScope` adds no
     * condition and an implicit `{cohort}` would resolve any teacher's group on
     * the platform before a policy ran.
     */
    public function cohort(Request $request, string $cohort, ResolveCohortConversation $action): JsonResponse
    {
        return $this->room($action->handle($this->currentUser($request), $cohort));
    }

    /**
     * Stop one person writing in this thread, and let them write again (FR-047).
     *
     * ⚠️ `moderate`, THE SAME ABILITY AS THE LOCK, and never `chat.reply`.
     * Silencing somebody is an act on what the room may say; handing it to
     * everyone who can answer a question gives every assistant the power to stop
     * a student asking one.
     */
    public function ban(Request $request, Conversation $conversation, SetConversationWriteBan $action): JsonResponse
    {
        $this->authorize('moderate', $conversation);

        $data = $request->validate([
            'user_uuid' => ['required', 'uuid'],
            // Mandatory, and it is the requirement rather than politeness: a
            // silent refusal reads as a fault and is retried until the ban lapses.
            'reason' => ['required', 'string', 'min:2', 'max:500'],
            // Null means open — lifted by hand. The ceiling is a week: past that
            // the honest instrument is the workspace ban, which is recorded and
            // appealable rather than a thread quietly closed to one person.
            'minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        try {
            $action->ban(
                $conversation,
                $this->currentUser($request),
                $data['user_uuid'],
                $data['reason'],
                $data['minutes'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'أُوقفت الكتابة.'], 201);
    }

    public function liftBan(Request $request, Conversation $conversation, SetConversationWriteBan $action): JsonResponse
    {
        $this->authorize('moderate', $conversation);

        $data = $request->validate(['user_uuid' => ['required', 'uuid']]);

        $action->lift($conversation, $this->currentUser($request), $data['user_uuid']);

        return response()->json(['message' => 'رُفع الإيقاف.']);
    }

    /**
     * ⚠️ 200 EVEN WHEN THE ROW WAS JUST WRITTEN, AND THE STATUS IS FORCED FOR THAT
     * REASON. Laravel answers 201 for any resource whose model `wasRecentlyCreated`
     * — so the FIRST person into a room would get a different status from everyone
     * after them, on the same `GET`, for a difference that is none of the client's
     * business. A caller checking `status === 200` would break for exactly one
     * reader per room, at random.
     */
    private function room(Conversation $conversation): JsonResponse
    {
        return ConversationResource::make($conversation->loadMissing(['student', 'lastMessage.sender']))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Close the discussion, or open it again.
     *
     * Authorised on `moderate`, the ability that already means «you may act on
     * what is said in this room» — a lock is moderation, not a reply, and giving
     * it to `chat.reply` would hand every assistant who can answer a question the
     * power to stop everyone else asking one.
     */
    public function lock(Request $request, Conversation $conversation, SetConversationLock $action): JsonResponse
    {
        $this->authorize('moderate', $conversation);

        try {
            $action->handle($conversation, $this->currentUser($request), $request->boolean('locked'));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ConversationResource::make($conversation->loadMissing(['student', 'lastMessage.sender']))->response();
    }

    public function helpful(Request $request, string $message, MarkHelpful $action): JsonResponse
    {
        return MessageResource::make(
            $action->handle($this->currentUser($request), $message)->loadMissing('sender')
        )->response();
    }
}
