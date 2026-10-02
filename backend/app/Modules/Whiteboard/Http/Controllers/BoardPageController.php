<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Whiteboard\Actions\AddBoardPage;
use App\Modules\Whiteboard\Actions\DeleteBoardPage;
use App\Modules\Whiteboard\Actions\ReorderBoardPages;
use App\Modules\Whiteboard\Actions\SaveBoardScene;
use App\Modules\Whiteboard\Data\SceneData;
use App\Modules\Whiteboard\Http\Requests\AddPageRequest;
use App\Modules\Whiteboard\Http\Requests\LockRequest;
use App\Modules\Whiteboard\Http\Requests\ReorderPagesRequest;
use App\Modules\Whiteboard\Http\Requests\SaveSceneRequest;
use App\Modules\Whiteboard\Http\Resources\PageResource;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * A board's pages. ⚠️ THE PAGE IS FOUND THROUGH THE BOARD, never bound on its own:
 * a page uuid of another board in the same workspace answers 404 here.
 */
class BoardPageController extends Controller
{
    public function scene(SaveSceneRequest $request, Board $board, string $page, SaveBoardScene $save): JsonResponse
    {
        $this->authorize('update', $board);

        return response()->json($save->handle(
            $board,
            // The save never reads the stored scene (up to 2 MB, every 1.5 s of
            // drawing); its conflict branch reads what it needs itself.
            $this->pageOf($board, $page, ['id', 'uuid', 'board_id', 'version']),
            $this->currentUser($request),
            SceneData::fromArray($request->validated()),
        ));
    }

    public function store(AddPageRequest $request, Board $board, AddBoardPage $add): JsonResponse
    {
        $this->authorize('update', $board);

        $after = $request->validated('after');
        $copyOf = $request->validated('duplicate_of');

        $page = $add->handle(
            $board,
            $this->currentUser($request),
            $request->tab(),
            is_string($after) ? $this->pageOf($board, $after) : null,
            is_string($copyOf) ? $this->pageOf($board, $copyOf) : null,
        );

        return (new PageResource($page))->response()->setStatusCode(201);
    }

    public function order(ReorderPagesRequest $request, Board $board, ReorderBoardPages $reorder): JsonResponse
    {
        $this->authorize('update', $board);

        return response()->json(['pages' => $reorder->handle($board, $this->currentUser($request), $request->tab(), $request->pages())]);
    }

    public function destroy(LockRequest $request, Board $board, string $page, DeleteBoardPage $delete): Response
    {
        $this->authorize('update', $board);

        $delete->handle($board, $this->currentUser($request), $request->tab(), $this->pageOf($board, $page));

        return response()->noContent();
    }

    /** @param list<string> $columns */
    private function pageOf(Board $board, string $uuid, array $columns = ['*']): BoardPage
    {
        return BoardPage::query()
            ->where('board_id', $board->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail($columns);
    }
}
