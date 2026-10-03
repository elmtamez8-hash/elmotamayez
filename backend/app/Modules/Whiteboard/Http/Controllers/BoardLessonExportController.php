<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        $user = $this->currentUser($request);
        $result = $action->handle($user, $board, $lesson, $this->asset($request));

        return response()->json($this->answer($user, $result), 201);
    }

    public function update(RecordBoardExportRequest $request, Board $board, string $export, RecordBoardExport $action): JsonResponse
    {
        $this->authorize('export', $board);

        // Found THROUGH the board: another board's export uuid reads as missing.
        $existing = BoardLessonExport::query()->where('board_id', $board->id)->where('uuid', $export)->firstOrFail();
        $lesson = Lesson::query()->findOrFail($existing->lesson_id);
        $user = $this->currentUser($request);
        $result = $action->handle($user, $board, $lesson, $this->asset($request), $existing);

        return response()->json($this->answer($user, $result));
    }

    private function asset(RecordBoardExportRequest $request): MediaAsset
    {
        return MediaAsset::query()->where('uuid', $request->validated('asset'))->firstOrFail();
    }

    /**
     * @param  array{export: BoardLessonExport, replaced: bool}  $result
     * @return array<string, mixed>
     */
    private function answer(User $user, array $result): array
    {
        $asset = $result['export']->mediaAsset;

        return [
            'export' => $result['export']->uuid,
            'attachment' => ['uuid' => $asset?->uuid],
            'replaced' => $result['replaced'],
            // Whether a NEXT export may replace this one (an assistant attaches once).
            'can_replace' => RecordBoardExport::canReplace($user, $asset),
        ];
    }
}
