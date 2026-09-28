<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\ListOnlineCounterparts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The green dots of the conversation list: the reader's own threads whose other
 * end has the product open. Conversation uuids only — never a user uuid, never a
 * time, never a «last seen».
 */
class ConversationPresenceController extends Controller
{
    public function index(Request $request, ListOnlineCounterparts $action): JsonResponse
    {
        return response()->json(['online' => $action->handle($this->currentUser($request))]);
    }
}
