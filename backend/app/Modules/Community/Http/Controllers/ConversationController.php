<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\ListConversations;
use App\Modules\Community\Actions\StartConversation;
use App\Modules\Community\Data\StartConversationData;
use App\Modules\Community\Http\Requests\StartConversationRequest;
use App\Modules\Community\Http\Resources\ConversationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The list screen and the «open a conversation» button.
 */
class ConversationController extends Controller
{
    public function index(Request $request, ListConversations $action): AnonymousResourceCollection
    {
        return ConversationResource::collection(
            $action->handle($this->currentUser($request))
        );
    }

    public function store(StartConversationRequest $request, StartConversation $action): JsonResponse
    {
        $conversation = $action->handle(
            $this->currentUser($request),
            StartConversationData::fromArray($request->validated()),
        );

        /*
        | 201 whether the row was written now or found already open. The client
        | asked for a conversation and has one; distinguishing the two would leak
        | the outcome of the race in `StartConversation` to a caller who has no
        | use for it, and would make two devices opening at once render
        | differently for no reason a person could name.
        */
        return ConversationResource::make($conversation->loadMissing(['student', 'lastMessage.sender']))
            ->response()
            ->setStatusCode(201);
    }
}
