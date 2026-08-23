<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\HideMessage;
use App\Modules\Community\Actions\PostMessage;
use App\Modules\Community\Actions\ReadMessages;
use App\Modules\Community\Data\PostMessageData;
use App\Modules\Community\Http\Requests\PostMessageRequest;
use App\Modules\Community\Http\Resources\MessageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reading a thread, writing into it, and taking a line back.
 *
 * ⚠️ `{conversation}` AND `{message}` ARE BARE STRINGS HERE. Neither is a
 * route-model binding: a student is a member of no workspace, so the global scope
 * adds nothing for them and an implicit binding would resolve any workspace's row
 * before a policy ran. Each Action resolves its own identifier after authorising.
 */
class MessageController extends Controller
{
    public function index(Request $request, string $conversation, ReadMessages $action): AnonymousResourceCollection
    {
        $before = $request->query('before');

        [, $messages] = $action->handle(
            $this->currentUser($request),
            $conversation,
            is_string($before) ? $before : null,
        );

        return MessageResource::collection($messages);
    }

    public function store(PostMessageRequest $request, string $conversation, PostMessage $action): JsonResponse
    {
        $message = $action->handle(
            $this->currentUser($request),
            PostMessageData::fromArray([
                'conversation' => $conversation,
                'body' => $request->validated('body'),
            ]),
        );

        /*
        | ⚠️ THE SAVED MESSAGE COMES BACK IN THE RESPONSE, AND THAT IS WHAT MAKES
        | `SC-015` REACHABLE. A client that never receives one socket frame still
        | renders what it just sent — the broadcast is an accelerator for the OTHER
        | party's screen, never the sender's confirmation.
        */
        return MessageResource::make($message->loadMissing('sender'))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $message, HideMessage $action): JsonResponse
    {
        $hidden = $action->handle($this->currentUser($request), $message);

        return MessageResource::make($hidden->loadMissing('sender'))->response();
    }
}
