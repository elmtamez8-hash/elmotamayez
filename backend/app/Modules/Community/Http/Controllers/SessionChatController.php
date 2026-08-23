<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\MarkHelpful;
use App\Modules\Community\Actions\ResolveSessionConversation;
use App\Modules\Community\Http\Resources\ConversationResource;
use App\Modules\Community\Http\Resources\MessageResource;
use App\Modules\Community\Models\Conversation;
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

    public function helpful(Request $request, string $message, MarkHelpful $action): JsonResponse
    {
        return MessageResource::make(
            $action->handle($this->currentUser($request), $message)->loadMissing('sender')
        )->response();
    }
}
