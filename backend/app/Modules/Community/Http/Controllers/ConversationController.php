<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\ListConversations;
use App\Modules\Community\Actions\ReadContactOptions;
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
        $include = $request->query('include');

        return ConversationResource::collection(
            $action->handle($this->currentUser($request), is_string($include) ? $include : null)
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
        return ConversationResource::make($conversation->loadMissing(['lastMessage.sender', ...ConversationResource::counterpartyLoads()]))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * What «تواصل مع المدرّس» can do for this reader with one teacher.
     *
     * The workspace arrives as a query string and is resolved inside the Action —
     * never an implicit binding, for the reason the routes file gives.
     */
    public function contactOptions(Request $request, ReadContactOptions $action): JsonResponse
    {
        $validated = $request->validate(['workspace' => ['required', 'string', 'uuid']]);

        return response()->json([
            'data' => $action->handle($this->currentUser($request), (string) $validated['workspace']),
        ]);
    }
}
