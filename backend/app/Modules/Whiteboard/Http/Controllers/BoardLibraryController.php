<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Whiteboard\Actions\ShareLibraryItem;
use App\Modules\Whiteboard\Http\Requests\ShareLibraryItemRequest;
use App\Modules\Whiteboard\Http\Resources\BoardLibraryItemResource;
use App\Modules\Whiteboard\Models\BoardLibraryItem;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;

/**
 * The academy's shared board library (owner decisions 2026-10-05): the list,
 * «شارك مع الأكاديمية», and the removal.
 */
class BoardLibraryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', BoardLibraryItem::class);

        // ponytail: one unpaged list, bounded by ShareLibraryItem::MAX_ITEMS.
        // Wrapped by hand: JsonResource::withoutWrapping() is on globally.
        return response()->json(['data' => BoardLibraryItemResource::collection(
            BoardLibraryItem::query()
                ->where('workspace_id', $this->workspaceId())
                ->with('creator:id,first_name,last_name')
                ->latest('id')
                ->get(),
        )]);
    }

    public function store(ShareLibraryItemRequest $request, ShareLibraryItem $share): JsonResponse
    {
        $this->authorize('create', BoardLibraryItem::class);

        /** @var array<mixed> $elements */
        $elements = $request->validated('elements');
        $item = $share->handle($this->currentUser($request), $this->workspaceId(), (string) $request->validated('name'), $elements);

        return (new BoardLibraryItemResource($item->load('creator:id,first_name,last_name')))->response()->setStatusCode(201);
    }

    public function destroy(BoardLibraryItem $item): JsonResponse
    {
        $this->authorize('delete', $item);

        $item->delete();

        return response()->json(null, 204);
    }

    private function workspaceId(): int
    {
        return (int) app(WorkspaceContext::class)->id();
    }
}
