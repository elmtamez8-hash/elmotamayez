<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Whiteboard\Actions\CreateBoard;
use App\Modules\Whiteboard\Actions\DeleteBoard;
use App\Modules\Whiteboard\Actions\DuplicateBoard;
use App\Modules\Whiteboard\Actions\ListBoards;
use App\Modules\Whiteboard\Actions\UpdateBoard;
use App\Modules\Whiteboard\Data\BoardData;
use App\Modules\Whiteboard\Http\Requests\ListBoardsRequest;
use App\Modules\Whiteboard\Http\Requests\StoreBoardRequest;
use App\Modules\Whiteboard\Http\Requests\UpdateBoardRequest;
use App\Modules\Whiteboard\Http\Resources\BoardDetailResource;
use App\Modules\Whiteboard\Http\Resources\BoardResource;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardOwnership;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Boards: list, create, open, rename/move (spec 039 · US1). Validation → DTO →
 * Action → Resource; the rules live in the Actions.
 */
class BoardController extends Controller
{
    public function index(ListBoardsRequest $request, ListBoards $list): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Board::class);

        return BoardResource::collection(
            $list->handle($this->currentUser($request), $this->workspaceId(), $request->filters()),
        );
    }

    public function store(StoreBoardRequest $request, CreateBoard $create): JsonResponse
    {
        $this->authorize('create', Board::class);

        $board = $create->handle(BoardData::fromArray($request->validated()), $this->workspaceId(), $this->currentUser($request));

        return (new BoardResource($this->loaded($board)))->response()->setStatusCode(201);
    }

    /** The first pages a board opens on carry their scene with `?scenes=first`; the rest come after. */
    public const FIRST_SCENES = 3;

    private const PAGE_COLUMNS = [
        'id', 'uuid', 'workspace_id', 'board_id', 'position', 'scene', 'scene_bytes', 'version',
        'client_tab', 'client_rev', 'background_asset_id', 'created_at', 'updated_at',
    ];

    public function show(Request $request, Board $board): BoardDetailResource
    {
        $this->authorize('view', $board);

        /*
         * `?scenes=first`: the board opens on its first screen, and the browser
         * asks for the whole board behind it. Every page keeps its version; the
         * pages past the first come WITHOUT their scene (not read at all — up to
         * 2 MB each), and the browser tracks a page's version only together with
         * its scene, so a save never pairs one response's scene with another's
         * version.
         */
        $first = $request->query('scenes') === 'first';
        $board = $this->loaded($board)->load([
            'pages' => fn ($pages) => $first ? $pages->select(array_diff(self::PAGE_COLUMNS, ['scene'])) : $pages,
            'pages.backgroundAsset:id,uuid',
            'lessonExports.lesson:id,uuid,title',
            'lessonExports.mediaAsset',
        ]);

        if ($first) {
            $opening = $board->pages->take(self::FIRST_SCENES);
            $scenes = BoardPage::query()->whereIn('id', $opening->modelKeys())->pluck('scene', 'id');
            $opening->each(fn (BoardPage $page) => $page->setAttribute('scene', $scenes[$page->getKey()] ?? null));
        }

        return new BoardDetailResource($board);
    }

    public function update(UpdateBoardRequest $request, Board $board, UpdateBoard $update): BoardResource
    {
        $this->authorize('update', $board);

        return new BoardResource($this->loaded(
            $update->handle($board, BoardData::fromArray($request->validated()), $this->currentUser($request)),
        ));
    }

    /** «نسخ»: 202 — the copy appears in the list when its job has filled it. */
    public function duplicate(Request $request, Board $board, DuplicateBoard $duplicate): JsonResponse
    {
        $this->authorize('view', $board);
        $this->authorize('create', Board::class);

        $copy = $duplicate->handle($board, $this->currentUser($request));

        return response()->json(['status' => 'copying', 'uuid' => $copy->uuid], 202);
    }

    public function destroy(Board $board, DeleteBoard $delete): JsonResponse
    {
        $this->authorize('delete', $board);

        $delete->handle($board);

        return response()->json(['status' => 'deleting'], 202);
    }

    private function loaded(Board $board): Board
    {
        $board->load(['owner:id,uuid,first_name,last_name', 'editor:id,uuid,first_name,last_name', 'lesson:id,uuid,title', 'classSession:id,uuid,starts_at']);
        BoardOwnership::primeFor(new Collection([$board]));

        return $board;
    }

    private function workspaceId(): int
    {
        $id = app(WorkspaceContext::class)->id();

        if ($id === null) {
            // The policy already refused a null context; reaching here means a route
            // without it, and a board written with no workspace belongs to nobody.
            throw new DomainException('تعذّر تحديد مكان العمل.');
        }

        return $id;
    }
}
