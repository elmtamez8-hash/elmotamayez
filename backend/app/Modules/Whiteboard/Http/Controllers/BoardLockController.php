<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Whiteboard\Actions\AcquireBoardLock;
use App\Modules\Whiteboard\Actions\ReleaseBoardLock;
use App\Modules\Whiteboard\Actions\TakeBoardLock;
use App\Modules\Whiteboard\Http\Requests\LockRequest;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The edit lock (R-09): acquire / heartbeat, release, and «خُذ التحرير».
 *
 * Every call asks the policy again — holding the lock is not permission, and an
 * assistant whose scope was taken away must stop at the next heartbeat.
 */
class BoardLockController extends Controller
{
    public function store(LockRequest $request, Board $board, AcquireBoardLock $acquire): JsonResponse
    {
        $this->authorize('update', $board);

        $result = $acquire->handle($board, $this->currentUser($request), $request->tab());

        if (! $result['held']) {
            $holder = $board->fresh(['editor:id,uuid,first_name,last_name'])?->editor;
            throw new WhiteboardRefusal('locked', [
                'held_by' => $holder === null ? null : ['uuid' => (string) $holder->uuid, 'name' => $holder->name],
            ]);
        }

        return response()->json($result);
    }

    public function destroy(LockRequest $request, Board $board, ReleaseBoardLock $release): Response
    {
        $this->authorize('update', $board);

        $release->handle($board, $this->currentUser($request), $request->tab());

        return response()->noContent();
    }

    public function take(LockRequest $request, Board $board, TakeBoardLock $take): JsonResponse
    {
        $this->authorize('takeLock', $board);

        return response()->json(['handover_at' => $take->handle($board, $request->tab())], 202);
    }
}
