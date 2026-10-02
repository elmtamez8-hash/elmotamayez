<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Whiteboard\Actions\SaveBoardScene;
use App\Modules\Whiteboard\Data\SceneData;
use App\Modules\Whiteboard\Http\Requests\SaveSceneRequest;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Http\JsonResponse;

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
            $this->pageOf($board, $page),
            $this->currentUser($request),
            SceneData::fromArray($request->validated()),
        ));
    }

    private function pageOf(Board $board, string $uuid): BoardPage
    {
        return BoardPage::query()
            ->where('board_id', $board->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
