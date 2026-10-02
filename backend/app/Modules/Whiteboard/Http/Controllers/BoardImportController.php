<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Whiteboard\Actions\CompleteBoardImport;
use App\Modules\Whiteboard\Actions\RequestBoardImport;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Modules\Whiteboard\Http\Requests\RequestBoardImportRequest;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardImport;
use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Http\JsonResponse;

/**
 * PDF imports (story 4): start one, say the file has arrived, ask how it goes.
 * The import and the page are always found THROUGH the board — another board's
 * uuid reads as missing.
 */
class BoardImportController extends Controller
{
    public function store(RequestBoardImportRequest $request, Board $board, RequestBoardImport $action): JsonResponse
    {
        $this->authorize('update', $board);

        $after = $request->validated('after');
        $result = $action->handle(
            $this->currentUser($request),
            $board,
            $request->tab(),
            (string) $request->validated('filename'),
            (int) $request->validated('size'),
            is_string($after) ? BoardPage::query()->where('board_id', $board->getKey())->where('uuid', $after)->firstOrFail() : null,
        );

        return response()->json([
            'import' => ['uuid' => $result['import']->uuid],
            'upload' => [
                'url' => $result['ticket']->url,
                'method' => $result['ticket']->method,
                'headers' => $result['ticket']->headers,
            ],
        ], 201);
    }

    public function complete(Board $board, string $import, CompleteBoardImport $action): JsonResponse
    {
        $this->authorize('update', $board);

        $done = $action->handle($this->importOf($board, $import));

        return response()->json(self::state($done), 202);
    }

    public function show(Board $board, string $import): JsonResponse
    {
        $this->authorize('view', $board);

        return response()->json(self::state($this->importOf($board, $import)));
    }

    private function importOf(Board $board, string $uuid): BoardImport
    {
        return BoardImport::query()->where('board_id', $board->getKey())->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array{status: string, failure_reason: string|null, pages_count: int|null, position: int|null} */
    private static function state(BoardImport $import): array
    {
        return [
            'status' => $import->status->value,
            'failure_reason' => $import->failure_reason?->value,
            'pages_count' => $import->pages_count,
            'position' => $import->status === BoardImportStatus::Queued ? self::position($import) : null,
        ];
    }

    /**
     * The place in the platform-wide line: imports queued before this one, plus
     * the one converting. Across workspaces — the line is one worker — so the
     * scope is bypassed by name; only a NUMBER leaves this method.
     */
    private static function position(BoardImport $import): int
    {
        $ahead = BoardImport::query()->withoutWorkspaceScope()
            ->where('status', BoardImportStatus::Queued)
            ->where(fn ($q) => $q->where('created_at', '<', $import->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $import->created_at)->where('id', '<', $import->id)))
            ->count();
        $converting = BoardImport::query()->withoutWorkspaceScope()->where('status', BoardImportStatus::Converting)->count();

        return $ahead + $converting + 1;
    }
}
