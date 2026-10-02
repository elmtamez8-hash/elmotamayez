<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Actions\RecordBoardExport;
use App\Modules\Whiteboard\Http\Requests\RecordBoardExportRequest;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardLessonExport;
use Illuminate\Http\JsonResponse;

/**
 * «إرفاق بمواد الدرس» (story 5): the first attachment, and its replacement on a
 * route of its own so `2fa.required` guards only the one that deletes (D2).
 */
class BoardLessonExportController extends Controller
{
    public function store(RecordBoardExportRequest $request, Board $board, RecordBoardExport $action): JsonResponse
    {
        $this->authorize('export', $board);

        $lesson = Lesson::query()->where('workspace_id', $board->workspace_id)->where('uuid', $request->validated('lesson'))->firstOrFail();
        $result = $action->handle($this->currentUser($request), $board, $lesson, $this->asset($request));

        return response()->json($this->answer($result), 201);
    }

    public function update(RecordBoardExportRequest $request, Board $board, string $export, RecordBoardExport $action): JsonResponse
    {
        $this->authorize('export', $board);

        // Found THROUGH the board: another board's export uuid reads as missing.
        $existing = BoardLessonExport::query()->where('board_id', $board->id)->where('uuid', $export)->firstOrFail();
        $lesson = Lesson::query()->findOrFail($existing->lesson_id);
        $result = $action->handle($this->currentUser($request), $board, $lesson, $this->asset($request), $existing);

        return response()->json($this->answer($result));
    }

    private function asset(RecordBoardExportRequest $request): MediaAsset
    {
        return MediaAsset::query()->where('uuid', $request->validated('asset'))->firstOrFail();
    }

    /**
     * @param  array{export: BoardLessonExport, replaced: bool}  $result
     * @return array<string, mixed>
     */
    private function answer(array $result): array
    {
        return [
            'export' => $result['export']->uuid,
            'attachment' => ['uuid' => $result['export']->mediaAsset?->uuid],
            'replaced' => $result['replaced'],
        ];
    }
}
